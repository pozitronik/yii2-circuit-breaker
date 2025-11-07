# Circuit Breaker для Yii2

[![Tests](https://github.com/pozitronik/yii2-circuit-breaker/actions/workflows/tests.yml/badge.svg)](https://github.com/pozitronik/yii2-circuit-breaker/actions/workflows/tests.yml)
[![Codecov](https://codecov.io/gh/pozitronik/yii2-circuit-breaker/branch/master/graph/badge.svg)](https://codecov.io/gh/pozitronik/yii2-circuit-breaker)
[![Packagist Version](https://img.shields.io/packagist/v/beeline/yii2-circuit-breaker)](https://packagist.org/packages/beeline/yii2-circuit-breaker)
[![Packagist License](https://img.shields.io/packagist/l/beeline/yii2-circuit-breaker)](https://packagist.org/packages/beeline/yii2-circuit-breaker)
[![Packagist Downloads](https://img.shields.io/packagist/dt/beeline/yii2-circuit-breaker)](https://packagist.org/packages/beeline/yii2-circuit-breaker)

Надёжная реализация паттерна Circuit Breaker для Yii2 приложений, предотвращающая каскадные сбои в распределённых системах.

## Возможности

- **Три состояния**: CLOSED (нормальная работа), OPEN (блокировка запросов), HALF_OPEN (тестирование восстановления)
- **Настраиваемые пороги**: Гибкая настройка частоты отказов, размера окна наблюдения и таймаутов
- **Скользящее окно**: Отслеживание истории последних запросов для точного расчёта частоты отказов
- **Автоматическое восстановление**: Тестирование работоспособности бэкенда после истечения таймаута
- **Нулевые зависимости**: Требуется только фреймворк Yii2
- **PHP 8.4+**: Современный PHP со строгой типизацией и новейшими возможностями

## Установка

```bash
composer require beeline/yii2-circuit-breaker
```

## Требования

- PHP >= 8.4
- Yii2 >= 2.0.45

## Использование

### Базовое использование

```php
use beeline\CircuitBreaker\CircuitBreaker;

$breaker = new CircuitBreaker([
    'failureThreshold' => 0.5,  // Открывать цепь при 50% отказов
    'windowSize' => 10,          // Отслеживать последние 10 запросов
    'timeout' => 30,             // Ждать 30 секунд перед тестированием восстановления
    'successThreshold' => 2,     // Требовать 2 успешных запроса для закрытия цепи
]);

// Проверяем, разрешён ли запрос
if ($breaker->allowsRequest()) {
    try {
        // Вызываем внешний сервис
        $result = $externalService->call();
        $breaker->recordSuccess();
    } catch (\Exception $e) {
        $breaker->recordFailure();
        throw $e;
    }
} else {
    // Цепь открыта, быстрый отказ
    throw new \RuntimeException('Сервис недоступен');
}
```

### Конфигурация

| Свойство | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| `failureThreshold` | float | 0.5 | Частота отказов (0.0-1.0) для открытия цепи |
| `windowSize` | int | 10 | Количество отслеживаемых запросов |
| `timeout` | int | 30 | Секунды ожидания перед попыткой восстановления |
| `successThreshold` | int | 1 | Необходимое количество успешных запросов в состоянии HALF_OPEN для закрытия цепи |

### Состояния цепи

#### CLOSED (Нормальная работа)
- Все запросы разрешены
- Отказы отслеживаются в скользящем окне
- Цепь открывается при превышении порога отказов

#### OPEN (Блокировка запросов)
- Запросы немедленно отклоняются
- Нет обращений к отказавшему бэкенду
- Переход в состояние HALF_OPEN после истечения таймаута

#### HALF_OPEN (Тестирование восстановления)
- Ограниченное количество запросов для тестирования бэкенда
- Закрывается при успешных запросах
- Повторно открывается при любом отказе

### Расширенное использование

#### Мониторинг состояния цепи

```php
// Проверка текущего состояния
if ($breaker->isClosed()) {
    // Нормальная работа
}

if ($breaker->isOpen()) {
    // Цепь открыта, используем запасной вариант
}

if ($breaker->isHalfOpen()) {
    // Тестирование восстановления
}

// Получение детальной статистики
$stats = $breaker->getStats();
// Возвращает: ['total' => 10, 'failures' => 3, 'failureRate' => 0.3]
```

#### Ручное управление (для тестирования)

```php
// Принудительное открытие цепи
$breaker->forceOpen();

// Принудительное закрытие цепи
$breaker->forceClose();

// Сброс в начальное состояние
$breaker->reset();
```

### Интеграция с внешними сервисами

```php
use beeline\CircuitBreaker\CircuitBreaker;
use yii\httpclient\Client;

class ExternalApiClient
{
    private CircuitBreaker $breaker;
    private Client $http;

    public function __construct()
    {
        $this->breaker = new CircuitBreaker([
            'failureThreshold' => 0.6,
            'windowSize' => 20,
            'timeout' => 60,
        ]);

        $this->http = new Client(['baseUrl' => 'https://api.example.com']);
    }

    public function fetchData(string $endpoint): array
    {
        if (!$this->breaker->allowsRequest()) {
            throw new \RuntimeException('Circuit API открыт');
        }

        try {
            $response = $this->http->get($endpoint)->send();

            if ($response->isOk) {
                $this->breaker->recordSuccess();
                return $response->data;
            }

            $this->breaker->recordFailure();
            throw new \RuntimeException('Ошибка запроса к API');
        } catch (\Exception $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }
}
```

## Принцип работы

1. **Скользящее окно**: Circuit breaker поддерживает окно фиксированного размера с результатами последних запросов
2. **Отслеживание отказов**: Результат каждого запроса (успех/отказ) записывается в окно
3. **Проверка порога**: Когда окно заполнено, частота отказов сравнивается с порогом
4. **Переходы между состояниями**:
   - CLOSED → OPEN: Частота отказов превышает порог
   - OPEN → HALF_OPEN: Истекает таймаут
   - HALF_OPEN → CLOSED: Достаточное количество успешных запросов
   - HALF_OPEN → OPEN: Происходит любой отказ

## Примеры использования

### Предотвращение перегрузки базы данных

```php
use beeline\CircuitBreaker\CircuitBreaker;

class DatabaseService
{
    private CircuitBreaker $breaker;

    public function __construct()
    {
        $this->breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 20,
            'timeout' => 10,
        ]);
    }

    public function executeQuery(string $sql): array
    {
        if (!$this->breaker->allowsRequest()) {
            // Возвращаем кешированные данные или бросаем исключение
            throw new \RuntimeException('База данных временно недоступна');
        }

        try {
            $result = Yii::$app->db->createCommand($sql)->queryAll();
            $this->breaker->recordSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->breaker->recordFailure();
            throw $e;
        }
    }
}
```

### Защита от сбоев внешних API

```php
use beeline\CircuitBreaker\CircuitBreaker;

class PaymentGateway
{
    private CircuitBreaker $breaker;

    public function __construct()
    {
        $this->breaker = new CircuitBreaker([
            'failureThreshold' => 0.3,  // Более чувствительный порог
            'windowSize' => 50,
            'timeout' => 60,
            'successThreshold' => 5,    // Требуем больше успехов для восстановления
        ]);
    }

    public function processPayment(array $paymentData): bool
    {
        if (!$this->breaker->allowsRequest()) {
            // Логируем событие и возвращаем ошибку
            Yii::error('Payment gateway circuit is open', __METHOD__);
            return false;
        }

        try {
            $response = $this->callPaymentApi($paymentData);
            $this->breaker->recordSuccess();
            return $response['success'];
        } catch (\Exception $e) {
            $this->breaker->recordFailure();
            Yii::error("Payment failed: {$e->getMessage()}", __METHOD__);
            return false;
        }
    }
}
```

### Мониторинг и алертинг

```php
use beeline\CircuitBreaker\CircuitBreaker;

class MonitoredService
{
    private CircuitBreaker $breaker;

    public function __construct()
    {
        $this->breaker = new CircuitBreaker([
            'failureThreshold' => 0.5,
            'windowSize' => 100,
            'timeout' => 30,
        ]);
    }

    public function call(): mixed
    {
        $state = $this->breaker->getState();

        // Отправляем метрики в систему мониторинга
        if ($state === 'open') {
            $this->sendAlert('Circuit breaker is OPEN');
        }

        if (!$this->breaker->allowsRequest()) {
            return $this->getFallbackData();
        }

        // ... выполнение запроса
    }

    public function getHealthStatus(): array
    {
        $stats = $this->breaker->getStats();

        return [
            'state' => $this->breaker->getState(),
            'total_requests' => $stats['total'],
            'failures' => $stats['failures'],
            'failure_rate' => $stats['failureRate'],
            'is_healthy' => $this->breaker->isClosed(),
        ];
    }
}
```

## Тестирование

```bash
# Запуск тестов
vendor/bin/phpunit

# Запуск тестов с покрытием
vendor/bin/phpunit --coverage-html coverage/
```

## Лицензия

GNU GPLv3.