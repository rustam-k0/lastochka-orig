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

    'source' => env('RABBITMQ_SOURCE', 'analytics'),

    'shop_code' => env('RABBITMQ_SHOP_CODE', 'default'),

    /*
    | Аналитика подписывается на ВСЕ события шины wildcard-биндингом '#'.
    | Обработка идёт через custom handler (минуя стандартный metadata/model
    | pipeline), transformer нужен только для validate() — он всегда true:
    | битые события не должны улетать в retry/DLQ, handler сам пишет их
    | как _unparsed в analytics_events.
    */
    'entities' => [
        'events' => [
            'enabled' => true,
            'model' => \App\Models\AnalyticsEvent::class,
            'transformer' => \App\RabbitMQ\Transformers\AnalyticsEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\AnalyticsEventHandler::class,
            'events' => [],
            'routing_key_patterns' => ['#'],
            'exchange' => 'default',
        ],
    ],

    'inbound_queues' => [
        'events' => env('RABBITMQ_QUEUE_ANALYTICS', 'analytics_events_in'),
    ],

    // Сервис только потребляет — outbox-воркер не нужен.
    'outbox' => [
        'enabled' => env('RABBITMQ_OUTBOX_ENABLED', false),
        'poll_interval' => 1,
        'batch_size' => 1000,
        'memory_limit' => 128,
        'retention_days' => 7,
    ],

    'fallback_queue' => [
        'connection' => 'database',
        'queue' => 'rabbitmq_retry',
    ],

    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'backoff' => [60, 300, 900],
    ],

    'consumer' => [
        'prefetch_count' => 1,
        'restart_after' => 1000,
        'memory_limit' => 128,
    ],

];
