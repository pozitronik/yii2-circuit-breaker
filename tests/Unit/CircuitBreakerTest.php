<?php
declare(strict_types=1);

namespace Beeline\CircuitBreaker\Tests\Unit;

use Beeline\CircuitBreaker\BreakerInterface;
use Beeline\CircuitBreaker\CircuitBreaker;
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

    /**
     * Тест: отслеживание текущей серии успехов
     *
     * Сценарий: при последовательных успешных запросах currentStreak
     * должен увеличиваться с каждым успехом, показывая положительное значение.
     */
    public function testCurrentStreakTracksSuccesses(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 10,
        ]);

        $breaker->recordSuccess();
        $stats = $breaker->getStats();
        self::assertEquals(1, $stats['currentStreak'], 'Серия должна быть 1 после одного успеха');

        $breaker->recordSuccess();
        $stats = $breaker->getStats();
        self::assertEquals(2, $stats['currentStreak'], 'Серия должна быть 2 после двух успехов');

        $breaker->recordSuccess();
        $stats = $breaker->getStats();
        self::assertEquals(3, $stats['currentStreak'], 'Серия должна быть 3 после трёх успехов');
    }

    /**
     * Тест: отслеживание текущей серии неудач
     *
     * Сценарий: при последовательных неудачных запросах currentStreak
     * должен уменьшаться (отрицательные значения), показывая серию отказов.
     */
    public function testCurrentStreakTracksFailures(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 10,
        ]);

        $breaker->recordFailure();
        $stats = $breaker->getStats();
        self::assertEquals(-1, $stats['currentStreak'], 'Серия должна быть -1 после одного отказа');

        $breaker->recordFailure();
        $stats = $breaker->getStats();
        self::assertEquals(-2, $stats['currentStreak'], 'Серия должна быть -2 после двух отказов');

        $breaker->recordFailure();
        $stats = $breaker->getStats();
        self::assertEquals(-3, $stats['currentStreak'], 'Серия должна быть -3 после трёх отказов');
    }

    /**
     * Тест: сброс серии при переключении с успехов на неудачи
     *
     * Сценарий: если была серия успехов, а затем произошёл отказ,
     * серия должна начаться заново с -1 (первый отказ).
     */
    public function testCurrentStreakResetsOnSuccessToFailureSwitch(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 10,
        ]);

        // Серия успехов
        $breaker->recordSuccess();
        $breaker->recordSuccess();
        $breaker->recordSuccess();

        $stats = $breaker->getStats();
        self::assertEquals(3, $stats['currentStreak'], 'Серия успехов: 3');

        // Переключение на отказ
        $breaker->recordFailure();
        $stats = $breaker->getStats();
        self::assertEquals(-1, $stats['currentStreak'], 'Серия должна сброситься на -1 после отказа');
    }

    /**
     * Тест: сброс серии при переключении с неудач на успехи
     *
     * Сценарий: если была серия неудач, а затем произошёл успех,
     * серия должна начаться заново с 1 (первый успех).
     */
    public function testCurrentStreakResetsOnFailureToSuccessSwitch(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 10,
        ]);

        // Серия неудач
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $stats = $breaker->getStats();
        self::assertEquals(-3, $stats['currentStreak'], 'Серия неудач: -3');

        // Переключение на успех
        $breaker->recordSuccess();
        $stats = $breaker->getStats();
        self::assertEquals(1, $stats['currentStreak'], 'Серия должна сброситься на 1 после успеха');
    }

    /**
     * Тест: отслеживание lastOpenedAt и totalOpens
     *
     * Сценарий: при каждом открытии цепи должна обновляться временная метка
     * lastOpenedAt и увеличиваться счётчик totalOpens.
     */
    public function testLastOpenedAtAndTotalOpensTracking(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 1,
            'successThreshold' => 1,
        ]);

        // Начальное состояние
        $stats = $breaker->getStats();
        self::assertNull($stats['lastOpenedAt'], 'lastOpenedAt должен быть null изначально');
        self::assertEquals(0, $stats['totalOpens'], 'totalOpens должен быть 0 изначально');

        // Первое открытие цепи
        $beforeOpen = time();
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        $stats = $breaker->getStats();
        self::assertNotNull($stats['lastOpenedAt'], 'lastOpenedAt должен быть установлен');
        self::assertGreaterThanOrEqual($beforeOpen, $stats['lastOpenedAt']);
        self::assertEquals(1, $stats['totalOpens'], 'totalOpens должен быть 1 после первого открытия');

        $firstOpenTime = $stats['lastOpenedAt'];

        // Восстанавливаем цепь
        sleep(2);
        self::assertTrue($breaker->isHalfOpen());
        $breaker->recordSuccess();
        self::assertTrue($breaker->isClosed());

        // Второе открытие цепи
        sleep(1); // Чтобы время точно изменилось
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        $stats = $breaker->getStats();
        self::assertGreaterThan($firstOpenTime, $stats['lastOpenedAt'], 'lastOpenedAt должен обновиться');
        self::assertEquals(2, $stats['totalOpens'], 'totalOpens должен быть 2 после второго открытия');
    }

    /**
     * Тест: сброс расширенной статистики при вызове reset()
     *
     * Сценарий: метод reset() должен очистить все новые поля статистики:
     * lastOpenedAt, totalOpens, currentStreak.
     */
    public function testResetClearsExtendedStatistics(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
        ]);

        // Создаём статистику
        $breaker->recordSuccess();
        $breaker->recordSuccess();
        $breaker->recordSuccess();

        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        $stats = $breaker->getStats();
        self::assertNotNull($stats['lastOpenedAt']);
        self::assertEquals(1, $stats['totalOpens']);
        self::assertNotEquals(0, $stats['currentStreak']);

        // Сбрасываем
        $breaker->reset();

        $stats = $breaker->getStats();
        self::assertNull($stats['lastOpenedAt'], 'lastOpenedAt должен быть сброшен в null');
        self::assertEquals(0, $stats['totalOpens'], 'totalOpens должен быть сброшен в 0');
        self::assertEquals(0, $stats['currentStreak'], 'currentStreak должен быть сброшен в 0');
    }

    /**
     * Тест: серия сбрасывается при закрытии цепи
     *
     * Сценарий: когда цепь закрывается из состояния HALF_OPEN,
     * currentStreak должен быть сброшен в 0.
     */
    public function testCurrentStreakResetsOnCircuitClose(): void
    {
        $breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 10,
            'timeout' => 1,
            'successThreshold' => 1,
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $breaker->recordFailure();
        }

        self::assertTrue($breaker->isOpen());

        // Ждём перехода в HALF_OPEN и закрываем цепь
        sleep(2);
        self::assertTrue($breaker->isHalfOpen());
        $breaker->recordSuccess();
        self::assertTrue($breaker->isClosed());

        // Проверяем, что серия сброшена
        $stats = $breaker->getStats();
        self::assertEquals(0, $stats['currentStreak'], 'currentStreak должен быть сброшен при закрытии цепи');
    }

    /**
     * Тест: getStats() возвращает все расширенные поля статистики
     *
     * Сценарий: проверяем, что метод getStats() возвращает все новые поля:
     * lastOpenedAt, totalOpens, currentStreak, а также старые поля.
     */
    public function testGetStatsReturnsExtendedFields(): void
    {
        $breaker = new CircuitBreaker([
            'windowSize' => 10,
        ]);

        $breaker->recordSuccess();
        $breaker->recordSuccess();

        $stats = $breaker->getStats();

        // Проверяем наличие всех полей
        self::assertArrayHasKey('total', $stats);
        self::assertArrayHasKey('failures', $stats);
        self::assertArrayHasKey('failureRate', $stats);
        self::assertArrayHasKey('lastOpenedAt', $stats);
        self::assertArrayHasKey('totalOpens', $stats);
        self::assertArrayHasKey('currentStreak', $stats);

        // Проверяем корректные значения
        self::assertEquals(2, $stats['total']);
        self::assertEquals(0, $stats['failures']);
        self::assertEquals(0.0, $stats['failureRate']);
        self::assertNull($stats['lastOpenedAt']);
        self::assertEquals(0, $stats['totalOpens']);
        self::assertEquals(2, $stats['currentStreak']);
    }
}
