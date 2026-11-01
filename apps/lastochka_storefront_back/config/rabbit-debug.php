<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable RabbitMQ Debug Dashboard
    |--------------------------------------------------------------------------
    |
    | This option controls whether the RabbitMQ debug dashboard is enabled.
    | By default, it is disabled in production.
    |
    */
    'enabled' => env('RABBIT_DEBUG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | API Key for Production Access
    |--------------------------------------------------------------------------
    |
    | If set, allows access to the debug dashboard on production
    | by passing ?key=YOUR_KEY or X-Rabbit-Debug-Key header.
    |
    */
    'api_key' => env('RABBIT_DEBUG_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Log Retention Days
    |--------------------------------------------------------------------------
    |
    | Number of days to keep log entries before automatic cleanup.
    |
    */
    'retention_days' => env('RABBIT_DEBUG_RETENTION', 7),

    /*
    |--------------------------------------------------------------------------
    | Hidden Fields
    |--------------------------------------------------------------------------
    |
    | Field names that should have their values replaced with [HIDDEN]
    | in the logged payload to protect sensitive data.
    |
    */
    'hidden_fields' => ['password', 'token', 'secret', 'api_key', 'apikey', 'access_token', 'refresh_token'],

    /*
    |--------------------------------------------------------------------------
    | Async Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, log entries are written via queue job to avoid
    | blocking the consumer. Recommended for production.
    |
    */
    'async_logging' => env('RABBIT_DEBUG_ASYNC', true),
];
