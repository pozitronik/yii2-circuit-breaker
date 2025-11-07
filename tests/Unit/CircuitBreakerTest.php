<?php
declare(strict_types=1);

namespace Beeline\CircuitBreaker\Tests\Unit;

use beeline\CircuitBreaker\BreakerInterface;
use beeline\CircuitBreaker\CircuitBreaker;
use PHPUnit\Framework\TestCase;

/**
 * Набор тестов для компонента CircuitBreaker
 */
class CircuitBreakerTest extends TestCase
{
    /**
     * Тест: начальное состояние - CLOSED
     */
    public function testInitialStateIsClosed(): void
    {
        $breaker = new CircuitBreaker();

        self::assertTrue($breaker->isClosed());
        self::assertFalse($breaker->isOpen());
        self::assertFalse($breaker->isHalfOpen());
        self::assertEquals(BreakerInterface::STATE_CLOSED, $breaker->getState());
    }

    /**
     * Тест: цепь пропускает запросы в состоянии CLOSED
     */
    public function testAllowsRequestWhenClosed(): void
    {
        $breaker = new CircuitBreaker();

        self::assertTrue($breaker->allowsRequest());
    }

    /**
     * Тест: регистрация успешных запросов в состоянии CLOSED
     */
    public function testRecordSuccessInClosedState(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 5,
        ]);

        $breaker->recordSuccess();
        $breaker->recordSuccess();
        $breaker->recordSuccess();

        $stats = $breaker->getStats();
        self::assertEquals(3, $stats['total']);
        self::assertEquals(0, $stats['failures']);
        self::assertEquals(0.0, $stats['failureRate']);
        self::assertTrue($breaker->isClosed());
    }

    /**
     * Тест: регистрация отказов в состоянии CLOSED
     */
    public function testRecordFailureInClosedState(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 5,
        ]);

        $breaker->recordFailure();
        $breaker->recordFailure();

        $stats = $breaker->getStats();
        self::assertEquals(2, $stats['total']);
        self::assertEquals(2, $stats['failures']);
        self::assertEquals(1.0, $stats['failureRate']);
    }

    /**
     * Тест: цепь открывается при превышении порога отказов
     */
    public function testCircuitOpensOnThresholdExceeded(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
        ]);

        // Заполняем окно успехами и отказами (60% отказов)
        for ($i = 0; $i < 10; $i++) {
            if ($i < 6) {
                $breaker->recordFailure();
            } else {
                $breaker->recordSuccess();
            }
        }

        // Цепь должна быть открыта
        self::assertTrue($breaker->isOpen());
        self::assertFalse($breaker->allowsRequest());
    }

    /**
     * Тест: цепь остается закрытой при уровне отказов ниже порога
     */
    public function testCircuitStaysClosedBelowThreshold(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
        ]);

        // Заполняем окно с 40% отказов
        for ($i = 0; $i < 10; $i++) {
            if ($i < 4) {
                $breaker->recordFailure();
            } else {
                $breaker->recordSuccess();
            }
        }

        // Цепь должна остаться закрытой
        self::assertTrue($breaker->isClosed());
        self::assertTrue($breaker->allowsRequest());
    }

    /**
     * Тест: скользящее окно сохраняет только windowSize количество запросов
     */
    public function testSlidingWindowMaintainsSize(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 5,
        ]);

        // Записываем больше, чем размер окна
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordSuccess();
        }

        $stats = $breaker->getStats();
        self::assertEquals(5, $stats['total'], 'Окно должно сохранять только последние 5 запросов');
    }

    /**
     * Тест: цепь переходит в состояние HALF_OPEN после таймаута
     */
    public function testCircuitTransitionsToHalfOpenAfterTimeout(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 1, // 1 секунда
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        // Ждем таймаута
        sleep(2);

        // Проверяем состояние (должна перейти в HALF_OPEN)
        self::assertTrue($breaker->isHalfOpen());
        self::assertTrue($breaker->allowsRequest());
    }

    /**
     * Тест: цепь закрывается из состояния HALF_OPEN при успешных запросах
     */
    public function testCircuitClosesFromHalfOpenOnSuccess(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 1,
            'successThreshold' => 2, // Нужно 2 успеха для закрытия
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        // Ждем таймаута и перехода в HALF_OPEN
        sleep(2);
        self::assertTrue($breaker->isHalfOpen());

        // Регистрируем успешные запросы
        $breaker->recordSuccess();
        self::assertTrue($breaker->isHalfOpen(), 'Должна остаться HALF_OPEN после 1 успеха');

        $breaker->recordSuccess();
        self::assertTrue($breaker->isClosed(), 'Должна закрыться после 2 успехов');
    }

    /**
     * Тест: цепь снова открывается из состояния HALF_OPEN при отказе
     */
    public function testCircuitReopensFromHalfOpenOnFailure(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 1,
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        // Ждем таймаута и перехода в HALF_OPEN
        sleep(2);
        self::assertTrue($breaker->isHalfOpen());

        // Регистрируем отказ - должна снова открыться
        $breaker->recordFailure();
        self::assertTrue($breaker->isOpen());
        self::assertFalse($breaker->allowsRequest());
    }

    /**
     * Тест: цепь не открывается при недостаточном количестве данных в окне
     */
    public function testCircuitDoesNotOpenWithInsufficientData(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
        ]);

        // Регистрируем только 5 отказов (недостаточно для заполнения окна)
        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure();
        }

        $stats = $breaker->getStats();
        self::assertEquals(1.0, $stats['failureRate'], 'Частота отказов 100%');
        self::assertTrue($breaker->isClosed(), 'Цепь должна остаться закрытой при недостаточных данных');
    }

    /**
     * Тест: метод forceOpen() принудительно открывает цепь
     */
    public function testForceOpen(): void
    {
        $breaker = new CircuitBreaker();

        self::assertTrue($breaker->isClosed());

        $breaker->forceOpen();

        self::assertTrue($breaker->isOpen());
        self::assertFalse($breaker->allowsRequest());
    }

    /**
     * Тест: метод forceClose() принудительно закрывает цепь
     */
    public function testForceClose(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        $breaker->forceClose();

        self::assertTrue($breaker->isClosed());
        self::assertTrue($breaker->allowsRequest());
    }

    /**
     * Тест: метод reset() сбрасывает состояние цепи
     */
    public function testReset(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        $breaker->reset();

        self::assertTrue($breaker->isClosed());
        $stats = $breaker->getStats();
        self::assertEquals(0, $stats['total'], 'Окно должно быть очищено');
    }

    /**
     * Тест: метод getStats() возвращает корректную статистику
     */
    public function testGetStatsReturnsCorrectStatistics(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 10,
        ]);

        // 7 успехов, 3 отказа
        for ($i = 0; $i < 10; $i++) {
            if ($i < 3) {
                $breaker->recordFailure();
            } else {
                $breaker->recordSuccess();
            }
        }

        $stats = $breaker->getStats();

        self::assertEquals(10, $stats['total']);
        self::assertEquals(3, $stats['failures']);
        self::assertEquals(0.3, $stats['failureRate']);
    }

    /**
     * Тест: граничный случай с нулевым размером окна
     */
    public function testZeroWindowSize(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 0,
        ]);

        $breaker->recordFailure();
        $breaker->recordFailure();

        $stats = $breaker->getStats();
        self::assertEquals(0, $stats['total']);
        self::assertTrue($breaker->isClosed(), 'Должна остаться закрытой с нулевым окном');
    }

    /**
     * Тест: граничный случай с порогом отказов 0.0
     */
    public function testFailureThresholdZero(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.0,
            'windowSize' => 10,
        ]);

        // Один отказ должен открыть цепь
        for ($i = 0; $i < 10; $i++) {
            if (0 === $i) {
                $breaker->recordFailure();
            } else {
                $breaker->recordSuccess();
            }
        }

        // Даже 1/10 отказов (10% частота отказов) превышает порог 0%
        self::assertTrue($breaker->isOpen());
    }

    /**
     * Тест: граничный случай с порогом отказов 1.0
     */
    public function testFailureThresholdOne(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 1.0,
            'windowSize' => 10,
        ]);

        // Все отказы должны открыть цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());
    }

    /**
     * Тест: несколько циклов открытия/закрытия цепи
     */
    public function testMultipleOpenCloseCycles(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 1,
            'successThreshold' => 1,
        ]);

        // Цикл 1: Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }
        self::assertTrue($breaker->isOpen());

        // Цикл 1: Закрываем цепь
        sleep(2);
        self::assertTrue($breaker->isHalfOpen(), 'Должна перейти в HALF_OPEN после таймаута');
        $breaker->recordSuccess();
        self::assertTrue($breaker->isClosed(), 'Должна закрыться после успеха в HALF_OPEN');

        // Цикл 2: Снова открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }
        self::assertTrue($breaker->isOpen(), 'Должна снова открыться после отказов');

        // Цикл 2: Снова закрываем цепь
        sleep(2);
        self::assertTrue($breaker->isHalfOpen(), 'Должна снова перейти в HALF_OPEN');
        $breaker->recordSuccess();
        self::assertTrue($breaker->isClosed(), 'Должна снова закрыться после успеха');
    }
}
