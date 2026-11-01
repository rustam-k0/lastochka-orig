# RabbitMQ: Базовая настройка

**Приоритет:** Критический (блокирует все остальные задачи)
**Зависимости:** Нет

---

## 1. Цель

Настроить пакет `multi_vendor_delivery_rabbitmq_package` (composer: `comrades/laravel-rabbitmq-events`) и подготовить инфраструктуру для синхронизации данных.

---

## 2. Задачи

### 2.1 Конфигурация

Создать `config/rabbitmq-events.php` с базовыми настройками:

```php
<?php

return [
    'enabled' => env('RABBITMQ_EVENTS_ENABLED', true),

    'connection' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    'exchanges' => [
        'default' => [
            'name' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'type' => 'topic',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    'source' => env('RABBITMQ_SOURCE', 'storefront'),
    'shop_code' => env('RABBITMQ_SHOP_CODE', 'default'),

    'entities' => [
        // Будут добавляться в следующих задачах
    ],

    'inbound_queues' => [
        'shops' => env('RABBITMQ_QUEUE_SHOPS', 'storefront_shops'),
        'categories' => env('RABBITMQ_QUEUE_CATEGORIES', 'storefront_categories'),
        'products' => env('RABBITMQ_QUEUE_PRODUCTS', 'storefront_products'),
        'stories' => env('RABBITMQ_QUEUE_STORIES', 'storefront_stories'),
        'merchant_sales' => env('RABBITMQ_QUEUE_PROMOTIONS', 'storefront_promotions'),
        'merchant_product_groups' => env('RABBITMQ_QUEUE_PRODUCT_GROUPS', 'storefront_product_groups'),
        'merchant_banners' => env('RABBITMQ_QUEUE_BANNERS', 'storefront_banners'),
        'merchant_promocodes' => env('RABBITMQ_QUEUE_PROMOCODES', 'storefront_promocodes'),
        'merchant_reviews' => env('RABBITMQ_QUEUE_STORE_REVIEWS', 'storefront_store_reviews'),
        'product_reviews' => env('RABBITMQ_QUEUE_PRODUCT_REVIEWS', 'storefront_product_reviews'),
    ],

    'outbox' => [
        'enabled' => env('RABBITMQ_OUTBOX_ENABLED', true),
        'poll_interval' => 1,
        'batch_size' => 1000,
        'memory_limit' => 128,
        'retention_days' => 7,
    ],

    'consumer' => [
        'prefetch_count' => 1,
        'restart_after' => 1000,
        'memory_limit' => 128,
    ],
];
```

### 2.2 Environment Variables

Добавить в `.env.example`:

```env
# RabbitMQ Connection
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

# RabbitMQ Events
RABBITMQ_EXCHANGE=app_events
RABBITMQ_SOURCE=storefront
RABBITMQ_SHOP_CODE=default
RABBITMQ_EVENTS_ENABLED=true
RABBITMQ_OUTBOX_ENABLED=true

# Queues (inbound)
RABBITMQ_QUEUE_SHOPS=storefront_shops
RABBITMQ_QUEUE_CATEGORIES=storefront_categories
RABBITMQ_QUEUE_PRODUCTS=storefront_products
RABBITMQ_QUEUE_STORIES=storefront_stories
RABBITMQ_QUEUE_PROMOTIONS=storefront_promotions
RABBITMQ_QUEUE_PRODUCT_GROUPS=storefront_product_groups
RABBITMQ_QUEUE_BANNERS=storefront_banners
RABBITMQ_QUEUE_PROMOCODES=storefront_promocodes
RABBITMQ_QUEUE_STORE_REVIEWS=storefront_store_reviews
RABBITMQ_QUEUE_PRODUCT_REVIEWS=storefront_product_reviews
```

### 2.3 Миграции пакета

Запустить миграции пакета (таблицы `event_outbox`, `event_sync_metadata`, `event_pending_references`):

```bash
php artisan migrate
```

### 2.4 Создать директорию Transformers

```
app/
└── RabbitMQ/
    ├── Transformers/
    └── Conditions/
```

### 2.5 Базовый абстрактный Transformer

Создать `app/RabbitMQ/Transformers/BaseEventTransformer.php`:

```php
<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use Comrades\RabbitMQEvents\Concerns\HasCustomEventHandlers;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;

abstract class BaseEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use HasCustomEventHandlers;
    use ResolvesExternalReferences;

    /**
     * Найти локальный ID сущности по external_id и source.
     */
    protected function resolveEntityId(
        string $entityType,
        ?string $externalId,
        ?string $source,
        ?string $shopCode = null
    ): ?int {
        if (!$externalId || !$source) {
            return null;
        }

        $query = EventSyncMetadata::where('entity_type', $entityType)
            ->where('external_id', $externalId)
            ->where('source', $source);

        if ($shopCode) {
            $query->where('shop_code', $shopCode);
        }

        return $query->value('entity_id');
    }

    /**
     * Стандартные обязательные поля для валидации.
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source'];
    }

    /**
     * Базовая валидация события.
     */
    public function validate(array $data): bool
    {
        foreach ($this->requiredFields() as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        return true;
    }
}
```

---

## 3. Ожидаемый результат

### Файлы
- [ ] `config/rabbitmq-events.php`
- [ ] `.env.example` обновлён
- [ ] `app/RabbitMQ/Transformers/BaseEventTransformer.php`
- [ ] Директории `app/RabbitMQ/Transformers/`, `app/RabbitMQ/Conditions/`

### Команды
```bash
# Проверить что миграции прошли
php artisan migrate:status | grep event

# Настроить exchanges
php artisan rabbitmq:setup-exchanges

# Проверить список команд
php artisan list rabbitmq
```

### Тесты
- [ ] `RabbitMQSetupTest::testConfigExists`
- [ ] `RabbitMQSetupTest::testMigrationsApplied`
- [ ] `RabbitMQSetupTest::testExchangesCreated`

---

## 4. Критерии готовности

- [ ] Конфиг создан и доступен через `config('rabbitmq-events.*')`
- [ ] Миграции применены (3 таблицы)
- [ ] Exchanges созданы в RabbitMQ
- [ ] BaseEventTransformer готов к наследованию
