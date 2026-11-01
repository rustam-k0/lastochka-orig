<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Events Package Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the entire package. When disabled, no events will be
    | published or consumed.
    |
    */

    'enabled' => env('RABBITMQ_EVENTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Connection Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your RabbitMQ server connection details.
    |
    */

    'connection' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Exchanges
    |--------------------------------------------------------------------------
    |
    | Define your RabbitMQ exchanges. The 'default' exchange is used for
    | all events unless specified otherwise in entity configuration.
    |
    */

    'exchanges' => [
        'default' => [
            'name' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'type' => 'topic',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Identifier
    |--------------------------------------------------------------------------
    |
    | Unique identifier for this application/microservice. Used in event
    | payloads to identify the source of events.
    |
    */

    'source' => env('RABBITMQ_SOURCE', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Source Timezone
    |--------------------------------------------------------------------------
    |
    | Таймзона внешнего издателя для меток event_timestamp БЕЗ явного офсета.
    | Пусто — метка читается в таймзоне приложения (поведение по умолчанию).
    | Задавать, когда издатель живёт в другой зоне: иначе момент уезжает на
    | разницу зон, а timestamp guard считает событие «из будущего» и глушит
    | всё, что придёт следом (mendev: 1С отдаёт красноярское время, сдвиг +4ч).
    | На метки с явным офсетом (`Z`, `+03:00`) не влияет.
    |
    */

    'source_timezone' => env('RABBITMQ_SOURCE_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Future Timestamp Tolerance
    |--------------------------------------------------------------------------
    |
    | Допуск в секундах на расхождение часов издателя и приёмника. Метка,
    | опережающая now() больше чем на этот допуск, схлопывается до now(),
    | чтобы не заблокировать последующие события этого типа.
    |
    */

    'future_timestamp_tolerance' => (int) env('RABBITMQ_FUTURE_TIMESTAMP_TOLERANCE', 60),

    /*
    |--------------------------------------------------------------------------
    | Shop Code
    |--------------------------------------------------------------------------
    |
    | Unique identifier for the shop/tenant. Used in event payloads.
    |
    */

    'shop_code' => env('RABBITMQ_SHOP_CODE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Entity Configurations
    |--------------------------------------------------------------------------
    |
    | Configure which models should publish events and how they should be
    | transformed. Each entity can have its own transformer, routing rules,
    | and conditions.
    |
    | Example (стандартная конфигурация с Eloquent events):
    | 'categories' => [
    |     'enabled' => true,
    |     'model' => \App\Models\Category::class,
    |     'transformer' => \App\Transformers\CategoryEventTransformer::class,
    |     'relations' => ['shopCategories.shop'],
    |     'events' => ['created', 'updated', 'deleted'],
    |     'routing_key_prefix' => 'category',
    |     'exchange' => 'default',
    |     'condition' => \App\RabbitMQ\Conditions\CategoryHasShopsCondition::class,
    |     'scout_sync' => false,
    | ],
    |
    | Custom Application Events (для сложных бизнес-событий):
    | Если у вас есть кастомное событие приложения (например, ProductUpdated),
    | которое триггерится после изменения основной модели и всех её связей
    | (цена, атрибуты, параметры и т.д.), используйте 'listen_events'.
    |
    | Example:
    | 'products' => [
    |     'enabled' => true,
    |     'model' => \App\Models\Product::class,
    |     'transformer' => \App\Transformers\ProductEventTransformer::class,
    |     'relations' => ['prices', 'attributes', 'categories'],
    |     'events' => ['created', 'deleted'],  // Стандартные Eloquent events
    |     'listen_events' => [
    |         \App\Events\ProductUpdated::class,  // Кастомное событие приложения
    |     ],
    |     'event_type' => 'updated',  // Тип события для listen_events (optional, default 'updated')
    |     'model_property' => 'product',  // Имя свойства с моделью в событии (optional, auto-detect)
    | ],
    |
    | Когда триггерится ProductUpdated::dispatch($product), пакет автоматически
    | опубликует событие product.updated в RabbitMQ со всеми связанными данными.
    |
    */

    'entities' => [
        'orders' => [
            'enabled' => true,
            'model' => \App\Models\Order::class,
            'transformer' => \App\Transformers\OrderEventTransformer::class,
            'relations' => ['orderItems.product', 'orderItems.originProduct', 'clientInfo', 'shop', 'client'],
            'events' => ['updated'],
            'routing_key_prefix' => 'order',
            'exchange' => 'default',
            'condition' => \App\RabbitMQ\Conditions\OrderNotSubOrderCondition::class,
        ],

        // Не публикуем/не консумим, но нужен для корректного резолва entity_type
        // в EventSyncMetadata при формировании order.updated событий (transformOrderItems)
        'products' => [
            'enabled' => false,
            'model' => \App\Models\Product::class,
        ],

        // Аналогично products: нужен для резолва entity_type клиента в
        // addExternalReferencesToEvent — иначе order.updated уходит с локальным
        // client_id вместо external (DASHOP, обратный синхрон заказов в магазин).
        'customers' => [
            'enabled' => false,
            'model' => \App\Models\Client::class,
        ],

        // Для резолва entity_type позиций заказа в transformOrderItems
        // (getEntityTypeFromClass(OrderItem::class) → 'order_items').
        'order_items' => [
            'enabled' => false,
            'model' => \App\Models\OrderItem::class,
        ],

        // Publish-only аналитическое событие (LASTOCHKI-JAMI-11659):
        // отражение каждой записи order_history в шину. Eloquent-events
        // выключены — публикация только из App\Services\AnalyticsEvents
        // (хук в OrderHistory::log). Потребитель — lastochka_analytics_back.
        // Префикс order_stage (НЕ order.*) — чтобы не попадать в
        // inbound-очереди сервисов, подписанные на order.*.
        'order_stages' => [
            'enabled' => true,
            'model' => \App\Models\OrderHistory::class,
            'transformer' => \App\Transformers\AnalyticsStageTransformer::class,
            'events' => [],
            'routing_key_prefix' => 'order_stage',
            'exchange' => 'default',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Queues
    |--------------------------------------------------------------------------
    |
    | Configure queues for consuming events from other services.
    |
    */

    'inbound_queues' => [
        // 'categories' => env('RABBITMQ_QUEUE_CATEGORIES', 'app_categories_in'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactional Outbox Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Transactional Outbox pattern worker settings.
    |
    */

    'outbox' => [
        'enabled' => env('RABBITMQ_OUTBOX_ENABLED', true),
        'poll_interval' => 1, // seconds between polling for unpublished events
        'batch_size' => 1000, // number of events to process per batch
        'memory_limit' => 128, // MB - worker will restart after reaching this
        'retention_days' => 7, // how long to keep published events
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure fallback queue for failed RabbitMQ publishes. This should
    | use a different queue driver (e.g., database) to avoid circular failures.
    |
    */

    'fallback_queue' => [
        'connection' => 'database', // Laravel queue connection (NOT rabbitmq)
        'queue' => 'rabbitmq_retry',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed event processing.
    |
    */

    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'backoff' => [60, 300, 900], // seconds to wait between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    |
    | Configure settings for the event consumer command.
    |
    */

    'consumer' => [
        'prefetch_count' => 1, // number of messages to prefetch from RabbitMQ
        'restart_after' => 1000, // restart consumer after N messages (memory leak prevention)
        'memory_limit' => 128, // MB - consumer will restart before reaching this
    ],

];
