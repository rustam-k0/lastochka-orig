<?php

return [
    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Events - Global Enable
    |--------------------------------------------------------------------------
    |
    | Master switch for the entire package.
    |
    */
    'enabled' => env('RABBITMQ_EVENTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Source Identifier
    |--------------------------------------------------------------------------
    |
    | Identifies this service as the source of events.
    | Used in event payloads for cross-service identification.
    |
    */
    'source' => env('RABBITMQ_SOURCE', '1c'),

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
    | Shop identifier for multi-tenant systems.
    |
    */
    'shop_code' => env('RABBITMQ_SHOP_CODE', 'aladdin'),

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Connection
    |--------------------------------------------------------------------------
    */
    'connection' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchanges
    |--------------------------------------------------------------------------
    |
    | Define RabbitMQ exchanges for publishing events.
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
    | Exchange Name (for consume commands)
    |--------------------------------------------------------------------------
    */
    'exchange_name' => env('RABBITMQ_EXCHANGE', 'app_events'),

    /*
    |--------------------------------------------------------------------------
    | Inbound Queues (for consume-all command)
    |--------------------------------------------------------------------------
    |
    | Maps entity types to queue names for consuming.
    |
    */
    'inbound_queues' => [
        // ПРИМЕЧАНИЕ: customers, bonus_operations и loyalty_cards здесь НЕ указаны намеренно.
        // rabbitmq:consume-all итерирует inbound_queues и запускает package-команду
        // rabbitmq:consume {entity} (через config entities), куда наши AMQPMessage-handler'ы
        // (CustomerConsumer/BonusOperationConsumer/LoyaltyCardConsumer из секции consumers)
        // не вписываются. Эти очереди потребляются отдельными командами
        // rabbitmq:consume-customers / rabbitmq:consume-bonus-operations /
        // rabbitmq:consume-loyalty-cards (см. consumers ниже) под своими supervisor-программами.
        'orders' => 'orders_to_1c',
        'integration_configs' => env('RABBITMQ_QUEUE_INTEGRATION_CONFIGS', '1c_parser_integration_configs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox Settings
    |--------------------------------------------------------------------------
    |
    | Transactional Outbox pattern configuration.
    |
    */
    'outbox' => [
        'enabled' => env('RABBITMQ_OUTBOX_ENABLED', true),
        'table' => 'event_outbox',
        'batch_size' => 1000,
        'retention_days' => 7,
        'poll_interval' => 1, // секунды между проверками при пустом outbox
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer/Publisher Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for background workers.
    |
    */
    'consumer' => [
        'restart_after' => (int) env('RABBITMQ_RESTART_AFTER', 100000), // рестарт после N событий
        'memory_limit' => (int) env('RABBITMQ_MEMORY_LIMIT', 256), // MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure RabbitMQ consumers for incoming messages.
    |
    */
    'consumers' => [
        'orders' => [
            'enabled' => env('RABBITMQ_CONSUME_ORDERS', true),
            'queue' => 'orders_to_1c',
            'exchange' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'routing_keys' => ['order.created', 'order.updated'],
            'handler' => \App\RabbitMQ\Consumers\OrderConsumer::class,
            'prefetch_count' => 10,
            'retry' => [
                'max_attempts' => 3,
                'delay_ms' => 5000,
            ],
            'dead_letter' => [
                'exchange' => 'app_events.dlx',
                'queue' => 'orders_to_1c.dlq',
            ],
        ],
        'customers' => [
            'enabled' => env('RABBITMQ_CONSUME_CUSTOMERS', true),
            'queue' => 'customers_to_1c',
            'exchange' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'routing_keys' => ['customer.created', 'customer.updated'],
            'handler' => \App\RabbitMQ\Consumers\CustomerConsumer::class,
            'prefetch_count' => 10,
            'retry' => [
                'max_attempts' => 3,
                'delay_ms' => 5000,
            ],
            'dead_letter' => [
                'exchange' => 'app_events.dlx',
                'queue' => 'customers_to_1c.dlq',
            ],
        ],
        'bonus_operations' => [
            'enabled' => env('RABBITMQ_CONSUME_BONUS_OPERATIONS', true),
            'queue' => 'bonus_operations_to_1c',
            'exchange' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'routing_keys' => ['bonus_operation.created'],
            'handler' => \App\RabbitMQ\Consumers\BonusOperationConsumer::class,
            'prefetch_count' => 10,
            'retry' => [
                'max_attempts' => 3,
                'delay_ms' => 5000,
            ],
            'dead_letter' => [
                'exchange' => 'app_events.dlx',
                'queue' => 'bonus_operations_to_1c.dlq',
            ],
        ],
        'loyalty_cards' => [
            'enabled' => env('RABBITMQ_CONSUME_LOYALTY_CARDS', true),
            'queue' => 'loyalty_cards_to_1c',
            'exchange' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'routing_keys' => ['loyalty_card.created'],
            'handler' => \App\RabbitMQ\Consumers\LoyaltyCardConsumer::class,
            'prefetch_count' => 10,
            'retry' => [
                'max_attempts' => 3,
                'delay_ms' => 5000,
            ],
            'dead_letter' => [
                'exchange' => 'app_events.dlx',
                'queue' => 'loyalty_cards_to_1c.dlq',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entities Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which models publish events to RabbitMQ.
    |
    */
    'entities' => [
        /*
        |----------------------------------------------------------------------
        | Categories
        |----------------------------------------------------------------------
        */
        'categories' => [
            // Observer всегда регистрируется; фильтрация события per-shop
            // через condition — читает integration_configs.sync_categories_enabled
            // (значение прилетает из admin_back через RabbitMQ, управляется
            // чекбоксом в форме 1С-интеграции в курьерской админке).
            'enabled' => true,
            'condition' => \App\RabbitMQ\Conditions\CategoriesSyncPublishCondition::class,
            'model' => \App\Models\Category::class,
            'transformer' => \App\RabbitMQ\Transformers\CategoryEventTransformer::class,
            'relations' => ['parent'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'category_1c',
            'exchange' => 'default',
            'outbox' => [
                'batch_size' => 1000,
                'retention_days' => 7,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Products
        |----------------------------------------------------------------------
        */
        'products' => [
            'enabled' => env('RABBITMQ_SYNC_PRODUCTS', true),
            'model' => \App\Models\Product::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductEventTransformer::class,
            'relations' => ['category', 'mainImage', 'retailPrice', 'stocks'],
            // created — новый товар; updated — изменение каталожных полей из 1С
            // (наименование, штрихкод, категория и т.п.). Лавину updated на каждый
            // обмен (synced_at=now()) режет ProductSyncPublishCondition.
            // deleted — товар пришёл с признаком DelProd=true из выгрузки.
            'events' => ['created', 'updated', 'deleted'],
            'condition' => \App\RabbitMQ\Conditions\ProductSyncPublishCondition::class,
            'routing_key_prefix' => 'product',
            'exchange' => 'default',
            'outbox' => [
                'batch_size' => 1000,
                'retention_days' => 7,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Orders (inbound from shop)
        |----------------------------------------------------------------------
        */
        'orders' => [
            'enabled' => env('RABBITMQ_CONSUME_ORDERS', true),
            'model' => \App\Models\Order::class,
            'transformer' => \App\RabbitMQ\Transformers\OrderEventTransformer::class,
            'relations' => ['items'],
            'events' => [], // только consume, без outbox публикации
            'routing_key_prefix' => 'order',
            'routing_key_patterns' => ['order.created', 'order.updated'],
            'exchange' => 'default',
        ],

        /*
        |----------------------------------------------------------------------
        | 1C Exchange Status (outbound diagnostics to admin_back)
        |----------------------------------------------------------------------
        */
        '1c_exchange_status' => [
            'enabled' => true,
            'model' => \App\Models\ExchangeLog::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductEventTransformer::class, // не используется для outbox, только для publishBatch lookup
            'events' => [], // без автоматической публикации, пишем в outbox вручную
            'routing_key_prefix' => '1c_exchange',
            'exchange' => 'default',
        ],

        /*
        |----------------------------------------------------------------------
        | Bonus Operations (outbound to storefront — manual outbox)
        |----------------------------------------------------------------------
        |
        | BonusOperationImportService пишет строки в event_outbox вручную
        | (entity_type=bonus_operations). Регистрация здесь нужна, чтобы
        | rabbitmq:publish-outbox (итерирует array_keys(entities)) забирал и
        | публиковал эти строки — аналогично 1c_exchange_status.
        |
        */
        'bonus_operations' => [
            'enabled' => true,
            'model' => \App\Models\BonusOperation::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductEventTransformer::class, // не используется для outbox, только для publishBatch lookup
            'events' => [], // ТОЛЬКО ручной outbox: и consumer, и import-сервис зовут BonusOperation::create(); живой observer привёл бы к двойной/ошибочной публикации
            'routing_key_prefix' => 'bonus_operation',
            'exchange' => 'default',
        ],

        /*
        |----------------------------------------------------------------------
        | Loyalty Cards / Barcode Prefixes (outbound to storefront — manual outbox)
        |----------------------------------------------------------------------
        |
        | Публикация loyalty_card.confirmed / barcode_prefixes.updated идёт прямым
        | EventOutbox::create; регистрация нужна, чтобы outbox-publisher знал entity_type.
        |
        */
        'loyalty_cards' => [
            'enabled' => false,
            'model' => \App\Models\Order::class,
            'routing_key_prefix' => 'loyalty_card',
        ],
        'barcode_prefixes' => [
            'enabled' => false,
            'model' => \App\Models\BarcodePrefix::class,
            'routing_key_prefix' => 'barcode_prefixes',
        ],

        /*
        |----------------------------------------------------------------------
        | Integration Configs (inbound from admin_back)
        |----------------------------------------------------------------------
        */
        'integration_configs' => [
            'enabled' => false, // только consume, не publish
            'model' => \App\Models\IntegrationConfig::class,
            'transformer' => \App\RabbitMQ\Transformers\IntegrationConfigEventTransformer::class,
            'routing_key_patterns' => [
                'integration_config.created',
                'integration_config.updated',
            ],
            'global_scope' => true,
        ],
    ],
];
