<?php
/** @noinspection PhpMemberCanBePulledUpInspection */
declare(strict_types=1);

namespace Beeline\CircuitBreaker;

use yii\base\Component;

/**
 * Реализация паттерна Circuit Breaker для предотвращения каскадных сбоев
 *
 * Состояния:
 * - CLOSED: Нормальная работа, запросы проходят, сбои отслеживаются
 * - OPEN: Слишком много сбоев, запросы немедленно отклоняются без обращения к бэкенду
 * - HALF_OPEN: Тестирование восстановления, ограниченное количество запросов для проверки бэкенда
 */
class CircuitBreaker extends Component implements BreakerInterface
{
    /**
     * Порог частоты отказов (0.0 - 1.0) для открытия цепи
     * Пример: 0.5 = открыть цепь при 50% неудачных запросов
     */
    public float $failureThreshold = 0.5;

    /**
     * Количество запросов в скользящем окне для отслеживания
     */
    public int $windowSize = 10;

    /**
     * Секунд ожидания перед попыткой восстановления (переход в HALF_OPEN)
     */
    public int $timeout = 30;

    /**
     * Количество успешных запросов в состоянии HALF_OPEN для закрытия цепи
     */
    public int $successThreshold = 1;

    /**
     * Текущее состояние цепи
     */
    private string $currentState = BreakerInterface::STATE_CLOSED;

    /**
     * Скользящее окно последних результатов запросов (true = успех, false = отказ)
     *
     * @var bool[]
     */
    private array $requestWindow = [];

    /**
     * Временная метка открытия цепи
     */
    private ?int $openedAt = null;

    /**
     * Количество последовательных успехов в состоянии HALF_OPEN
     */
    private int $halfOpenSuccesses = 0;

    /**
     * Временная метка последнего открытия цепи (для статистики)
     */
    private ?int $lastOpenedAt = null;

    /**
     * Общее количество открытий цепи
     */
    private int $totalOpens = 0;

    /**
     * Текущая серия успехов/неудач (положительное = успехи, отрицательное = неудачи)
     */
    private int $currentStreak = 0;

    /**
     * Проверить, разрешен ли запрос
     *
     * @return bool True если запрос может быть выполнен, false если цепь открыта
     */
    public function allowsRequest(): bool
    {
        $this->updateState();

        return match ($this->currentState) {
            BreakerInterface::STATE_CLOSED, BreakerInterface::STATE_HALF_OPEN => true,
            BreakerInterface::STATE_OPEN => false,
        };
    }

    /**
     * Зарегистрировать успешный запрос
     */
    public function recordSuccess(): void
    {
        if ($this->currentState === BreakerInterface::STATE_HALF_OPEN) {
            $this->halfOpenSuccesses++;

            if ($this->halfOpenSuccesses >= $this->successThreshold) {
                $this->closeCircuit();
            }
        } elseif ($this->currentState === BreakerInterface::STATE_CLOSED) {
            $this->addToWindow(true);
            // Обновляем серию успехов
            $this->currentStreak = $this->currentStreak >= 0 ? $this->currentStreak + 1 : 1;
            // Проверяем порог и после успеха (цепь может нуждаться в открытии)
            $this->checkThreshold();
        }
    }

    /**
     * Зарегистрировать неудачный запрос
     */
    public function recordFailure(): void
    {
        if ($this->currentState === BreakerInterface::STATE_HALF_OPEN) {
            $this->openCircuit();
        } elseif ($this->currentState === BreakerInterface::STATE_CLOSED) {
            $this->addToWindow(false);
            // Обновляем серию неудач (отрицательные значения)
            $this->currentStreak = $this->currentStreak <= 0 ? $this->currentStreak - 1 : -1;
            $this->checkThreshold();
        }
    }

    /**
     * Получить текущее состояние
     */
    public function getState(): string
    {
        $this->updateState();
        return $this->currentState;
    }

    /**
     * Проверить, закрыта ли цепь (нормальная работа)
     */
    public function isClosed(): bool
    {
        return $this->getState() === BreakerInterface::STATE_CLOSED;
    }

    /**
     * Проверить, открыта ли цепь (запросы блокируются)
     */
    public function isOpen(): bool
    {
        return $this->getState() === BreakerInterface::STATE_OPEN;
    }

    /**
     * Проверить, находится ли цепь в полуоткрытом состоянии (тестирование)
     */
    public function isHalfOpen(): bool
    {
        return $this->getState() === BreakerInterface::STATE_HALF_OPEN;
    }

    /**
     * Принудительно открыть цепь (полезно для тестирования)
     */
    public function forceOpen(): void
    {
        $this->openCircuit();
    }

    /**
     * Принудительно закрыть цепь (полезно для тестирования)
     */
    public function forceClose(): void
    {
        $this->closeCircuit();
    }

    /**
     * Сбросить circuit breaker в начальное состояние
     */
    public function reset(): void
    {
        $this->currentState = BreakerInterface::STATE_CLOSED;
        $this->requestWindow = [];
        $this->openedAt = null;
        $this->halfOpenSuccesses = 0;
        $this->lastOpenedAt = null;
        $this->totalOpens = 0;
        $this->currentStreak = 0;
    }

    /**
     * Получить статистику отказов
     *
     * @return array{total: int, failures: int, failureRate: float, lastOpenedAt: int|null, totalOpens: int, currentStreak: int}
     */
    public function getStats(): array
    {
        $total = count($this->requestWindow);
        $failures = count(array_filter($this->requestWindow, static fn($success) => !$success));

        return [
            'total' => $total,
            'failures' => $failures,
            'failureRate' => $total > 0 ? $failures / $total : 0.0,
            'lastOpenedAt' => $this->lastOpenedAt,
            'totalOpens' => $this->totalOpens,
            'currentStreak' => $this->currentStreak,
        ];
    }

    /**
     * Обновить состояние на основе таймаута
     */
    private function updateState(): void
    {
        if ($this->currentState === BreakerInterface::STATE_OPEN && $this->shouldAttemptReset()) {
            $this->currentState = BreakerInterface::STATE_HALF_OPEN;
            $this->halfOpenSuccesses = 0;
        }
    }

    /**
     * Проверить, прошло ли достаточно времени для попытки восстановления
     */
    private function shouldAttemptReset(): bool
    {
        return null !== $this->openedAt
            && (time() - $this->openedAt) >= $this->timeout;
    }

    /**
     * Добавить результат в скользящее окно
     *
     * @param bool $success True для успеха, false для отказа
     */
    private function addToWindow(bool $success): void
    {
        $this->requestWindow[] = $success;

        // Оставляем только последние N запросов
        if (count($this->requestWindow) > $this->windowSize) {
            array_shift($this->requestWindow);
        }
    }

    /**
     * Проверить, превышен ли порог отказов
     */
    private function checkThreshold(): void
    {
        if (count($this->requestWindow) < $this->windowSize) {
            return; // Недостаточно данных
        }

        $stats = $this->getStats();

        if ($stats['failureRate'] >= $this->failureThreshold) {
            $this->openCircuit();
        }
    }

    /**
     * Открыть цепь
     */
    private function openCircuit(): void
    {
        $this->currentState = BreakerInterface::STATE_OPEN;
        $this->openedAt = time();
        $this->lastOpenedAt = $this->openedAt;
        $this->totalOpens++;
        $this->halfOpenSuccesses = 0;
    }

    /**
     * Закрыть цепь
     */
    private function closeCircuit(): void
    {
        $this->currentState = BreakerInterface::STATE_CLOSED;
        $this->openedAt = null;
        $this->halfOpenSuccesses = 0;
        $this->currentStreak = 0; // Сбрасываем серию при восстановлении
        $this->requestWindow = []; // Сбрасываем окно при восстановлении
    }
}
