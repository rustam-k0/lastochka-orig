<?php

use Illuminate\Support\Str;

return [

  /*
  |--------------------------------------------------------------------------
  | Default Cache Store
  |--------------------------------------------------------------------------
  |
  | This option controls the default cache connection that gets used while
  | using this caching library. This connection is used when another is
  | not explicitly specified when executing a given caching function.
  |
  */

  'default' => env('CACHE_DRIVER', 'file'),

  /*
  |--------------------------------------------------------------------------
  | Lock Store
  |--------------------------------------------------------------------------
  |
  | Стор для атомарных блокировок (Cache::lock). Отделён от основного кэша
  | намеренно: файловый кэш пишут два разных юзера (php-fpm и CI-раннер,
  | прогревающий lighthouse:cache), и созданный раннером подкаталог
  | storage/framework/cache/data/** оказывается недоступен www-data —
  | блокировка падает с «Unable to create lockable file», а вместе с ней
  | и вся операция под ней (прод Ласточки 11.09.2026, сборка 255).
  |
  | database работает на всех форках без дополнительных расширений и
  | переживает несколько php-fpm хостов. Форк с поднятым Redis может
  | переключиться через CACHE_LOCK_STORE=redis.
  |
  */

  'lock_store' => env('CACHE_LOCK_STORE', 'database'),

  /*
  |--------------------------------------------------------------------------
  | Cache Stores
  |--------------------------------------------------------------------------
  |
  | Here you may define all of the cache "stores" for your application as
  | well as their drivers. You may even define multiple stores for the
  | same cache driver to group types of items stored in your caches.
  |
  | Supported drivers: "apc", "array", "database", "file",
  |         "memcached", "redis", "dynamodb", "octane", "null"
  |
  */

  'stores' => [

    'apc' => [
      'driver' => 'apc',
    ],

    'array' => [
      'driver' => 'array',
      'serialize' => false,
    ],

    'database' => [
      'driver' => 'database',
      'table' => 'cache',
      'connection' => null,
      'lock_connection' => null,
      'lock_table' => 'cache_locks',
    ],

    'file' => [
      'driver' => 'file',
      'path' => storage_path('framework/cache/data'),
    ],

    'memcached' => [
      'driver' => 'memcached',
      'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
      'sasl' => [
        env('MEMCACHED_USERNAME'),
        env('MEMCACHED_PASSWORD'),
      ],
      'options' => [
        // Memcached::OPT_CONNECT_TIMEOUT => 2000,
      ],
      'servers' => [
        [
          'host' => env('MEMCACHED_HOST', '127.0.0.1'),
          'port' => env('MEMCACHED_PORT', 11211),
          'weight' => 100,
        ],
      ],
    ],

    'redis' => [
      'driver' => 'redis',
      'connection' => 'cache',
      'lock_connection' => 'default',
    ],

    'dynamodb' => [
      'driver' => 'dynamodb',
      'key' => env('AWS_ACCESS_KEY_ID'),
      'secret' => env('AWS_SECRET_ACCESS_KEY'),
      'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
      'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
      'endpoint' => env('DYNAMODB_ENDPOINT'),
    ],

    'octane' => [
      'driver' => 'octane',
    ],

  ],

  /*
  |--------------------------------------------------------------------------
  | Cache Key Prefix
  |--------------------------------------------------------------------------
  |
  | When utilizing a RAM based store such as APC or Memcached, there might
  | be other applications utilizing the same cache. So, we'll specify a
  | value to get prefixed to all our keys so we can avoid collisions.
  |
  */

  'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_cache'),

];
