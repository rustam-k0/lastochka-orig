<?php

return [
    /*
     * Токен доступа к OpenAPI-документации (`/docs/api`, `/docs/api.json`) на
     * окружениях, где APP_ENV != local. Пустое значение = документация закрыта
     * (fail-safe: без явно заданного токена прод остаётся с 403).
     */
    'token' => env('API_DOCS_TOKEN'),

    /*
     * Служебные пути, которых в документации нет никогда: серверные коллбэки
     * платёжных систем и служб доставки, внутренние вызовы между сервисами и
     * отладочные ручки. Клиентским API они не являются.
     */
    'internal_paths' => [
        'debug/*',
        'v1/webhooks/*',
        'v1/internal/*',
    ],

    /*
     * Пути функциональности, которой нет в этом проекте. Маски (`Str::is`)
     * перечисляются через запятую в API_DOCS_HIDDEN_PATHS — набор свой у
     * каждого стенда, код при этом остаётся общим.
     */
    'hidden_paths' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('API_DOCS_HIDDEN_PATHS', ''))
    ), static fn (string $pattern): bool => $pattern !== '')),

    /*
     * Пути модульных фич: скрываются автоматически, когда модуль выключен в
     * project_modules. Ключ — ключ модуля, значение — маски путей.
     */
    'module_paths' => [
        'preorders' => ['v1/preorders', 'v1/products/*/preorders'],
        'product_issue_reports' => ['v1/products/*/report-issue'],
        'product_documents' => ['v1/products/*/documents'],
        'product_group_filters' => ['v1/product-group-filters'],
        'brands' => ['v1/brands'],
        'delivery_cdek' => ['v1/pickup/cdek', 'v1/pickup/cdek/*', 'v1/delivery/cdek/*'],
        'delivery_russian_post' => [
            'v1/pickup/russianpost',
            'v1/pickup/russianpost/*',
            'v1/delivery/russianpost/*',
        ],
    ],

    /*
     * Поля схем, которые принадлежат модульным фичам: при выключенном модуле
     * поля нет в ответе, поэтому нет и в документации. Формат «Схема.поле».
     */
    'module_properties' => [
        'b2b' => ['StoreResource.isB2b', 'OrderResource.isB2b'],
    ],

    /*
     * Пути режима маркетплейса: вне marketplace_mode их в проекте нет.
     */
    'marketplace_paths' => [
        'v1/cart/delivery-summary',
        'v1/client-orders',
        'v1/client-orders/*',
        'v1/delivery/options',
        'v1/delivery/calculate',
        'v1/pickup/cities',
        'v1/customer/addresses/pickup',
    ],
];
