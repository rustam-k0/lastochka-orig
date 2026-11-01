<?php

return [
    // ✅ CRITICAL: Уникальный идентификатор этого сервиса
    // Используется для предотвращения loopback и определения владельца сущности
    'source' => env('RB_EVENT_SOURCE', 'retailbridge'),

    // Имя exchange для публикации/консьюма
    'exchange_name' => env('RB_EXCHANGE_NAME', 'retailbridge'),

    // Настройки логирования
    'logging' => [
        // Логировать все входящие сообщения из RabbitMQ
        'log_incoming_messages' => env('RB_LOG_INCOMING_MESSAGES', true),

        // Логировать сырой JSON (может быть объемным)
        'log_raw_body' => env('RB_LOG_RAW_BODY', true),

        // Логировать успешные ACK
        'log_successful_ack' => env('RB_LOG_SUCCESSFUL_ACK', true),
    ],

    'exchanges' => [
        'default' => [
            'name' => env('RB_DEFAULT_EXCHANGE', 'orders'),
        ],
    ],

    // Очереди для входящих событий по сущностям
    'inbound_queues' => [
        'orders' => env('RB_ORDERS_QUEUE', 'retailbridge.orders'),
    ],

    // Настройки консьюмера
    'consumer' => [
        'prefetch_count' => (int) env('RB_CONSUMER_PREFETCH', 1),
        'restart_after' => (int) env('RB_CONSUMER_RESTART_AFTER', 1000),
        'memory_limit' => (int) env('RB_CONSUMER_MEMORY_MB', 128),
    ],

    'outbox' => [
        'batch_size' => (int) env('RB_OUTBOX_BATCH', 200),
    ],

    // Конфигурация сущностей (фокус: orders, customers)
    'entities' => [
        'order' => [
            'routing_key_patterns' => [
                'order.#',
            ],
            'delay_seconds' => (int) env('RB_ORDERS_DELAY_SECONDS', 0),
        ],
        'customer' => [
            'routing_key_patterns' => [
                'customer.created',
                'customer.updated',
                'customer.deleted',
            ],
            'delay_seconds' => (int) env('RB_CUSTOMERS_DELAY_SECONDS', 0),
        ],
        'delivery_address' => [
            'routing_key_patterns' => [
                'customer.address.#',
            ],
            'delay_seconds' => (int) env('RB_DELIVERY_ADDRESS_DELAY_SECONDS', 0),
        ],
        'product' => [
            'routing_key_patterns' => [
                'product.#',
            ],
            'delay_seconds' => (int) env('RB_PRODUCTS_DELAY_SECONDS', 0),
        ],
        'category' => [
            'routing_key_patterns' => [
                'category.#',
            ],
            'delay_seconds' => (int) env('RB_CATEGORIES_DELAY_SECONDS', 0),
        ],
        'shop' => [
            'routing_key_patterns' => [
                'shop.#',
            ],
            'delay_seconds' => (int) env('RB_SHOPS_DELAY_SECONDS', 0),
        ],
//        'user' => [
//            'routing_key_patterns' => [
//                'user.#',
//            ],
//            'delay_seconds' => (int) env('RB_USERS_DELAY_SECONDS', 0),
//        ],
    ],
];
