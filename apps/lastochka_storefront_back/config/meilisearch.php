<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Meilisearch Host
    |--------------------------------------------------------------------------
    |
    | Адрес сервера Meilisearch. В Docker используйте имя сервиса.
    |
    */
    'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),

    /*
    |--------------------------------------------------------------------------
    | Meilisearch API Key
    |--------------------------------------------------------------------------
    |
    | Master Key для индексации (admin operations).
    | В production используйте отдельные ключи для поиска и индексации.
    |
    */
    'key' => env('MEILISEARCH_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Timeouts (milliseconds)
    |--------------------------------------------------------------------------
    */
    'connect_timeout' => (int) env('MEILISEARCH_CONNECT_TIMEOUT', 2000),
    'request_timeout' => (int) env('MEILISEARCH_REQUEST_TIMEOUT', 5000),

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Количество попыток и базовая задержка для exponential backoff.
    |
    */
    'retry' => [
        'max_attempts' => 3,
        'base_delay_ms' => 100, // 100ms, 200ms, 400ms
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Names
    |--------------------------------------------------------------------------
    */
    'indexes' => [
        'products' => 'products',
    ],

    /*
    |--------------------------------------------------------------------------
    | Products Index Settings
    |--------------------------------------------------------------------------
    |
    | Настройки индекса товаров для оптимального поиска.
    |
    | Сетевой каталог (модуль network_catalog): документ остаётся ОДИН на товар
    | (карточка глобальна, владелец — якорный магазин), структура полей не
    | меняется. Но store_id, price, quantity и in_stock в нём — с якорной
    | карточки, то есть якорный id и нули: цена, остаток и принадлежность
    | магазину живут в store_products. Поэтому в сетевом режиме эти поля НЕ
    | используются как фильтры поиска — Meilisearch отвечает только за
    | текстовый матч, а доступность и цену считает БД по офферам магазина
    | (см. App\Services\Search\SearchService::networkVisibilityFilters()).
    |
    */
    'products_index' => [
        'searchableAttributes' => [
            'title',
            'sku',
        ],
        'filterableAttributes' => [
            'store_id',
            'category_ids',
            'brand_id',
            'is_active',
            'is_configurable',
            'quantity',
            'in_stock',
            'price',
            'sugar_free',
            'has_discount',
            'adult_only',
        ],
        'sortableAttributes' => [
            'price',
            'rating',
            'updated_at',
        ],
        'rankingRules' => [
            'words',
            'typo',
            'proximity',
            'attribute',
            'sort',
            'exactness',
        ],
        'typoTolerance' => [
            'enabled' => true,
            'minWordSizeForTypos' => [
                'oneTypo' => 4,
                'twoTypos' => 8,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Время кеширования результатов поиска.
    |
    */
    'cache_ttl' => (int) env('MEILISEARCH_CACHE_TTL', 30),

    /*
    |--------------------------------------------------------------------------
    | Search Limits
    |--------------------------------------------------------------------------
    */
    'search' => [
        'max_hits' => 1000, // Для агрегации по магазинам
        'default_per_page' => 10,
        'max_per_page' => 100,
        'default_products_per_store' => 10,
        'max_products_per_store' => 50,
        'suggestions_limit' => 20,
        'default_categories_limit' => 5,
        'max_categories_limit' => 20,
        // Размер секции categories у поиска в магазине (чипы-фильтры в мобилке).
        'store_categories_limit' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Query Log
    |--------------------------------------------------------------------------
    |
    | Лог поиска в магазине для отчёта «искали, но не нашли»
    | (App\Services\Search\SearchQueryLogger, таблица search_query_logs).
    | series_window_seconds — пауза между запросами одного клиента, в которую
    | продолжение набора ещё считается той же серией.
    |
    */
    'query_log' => [
        'enabled' => (bool) env('SEARCH_QUERY_LOG_ENABLED', true),
        'series_window_seconds' => (int) env('SEARCH_QUERY_LOG_SERIES_WINDOW', 60),
        'unavailable_limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reindex Settings
    |--------------------------------------------------------------------------
    */
    'reindex' => [
        'chunk_size' => 1000,
    ],
];
