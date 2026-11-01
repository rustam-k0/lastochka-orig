<?php

return [
    'url' => env('RETAILCRM_URL', 'https://your-retailcrm-instance.retailcrm.ru'),
    'api_key' => env('RETAILCRM_API_KEY', 'your-api-key'),
    'version' => env('RETAILCRM_VERSION', 'v5'),
    'shop_code' => env('RETAILCRM_SHOP_CODE', 'default'),
    'warehouse_code' => env('RETAILCRM_WAREHOUSE_CODE', 'warehouse'),
    'default_catalog_id' => (int) env('RETAILCRM_DEFAULT_CATALOG_ID', 1),

    // Источник для событий из RetailCRM
    'source' => env('RB_EVENT_SOURCE', 'retailcrm'),

    // Маппинг статусов заказов (Outbound: System -> RetailCRM)
    'order_statuses' => [
        'created' => 'new',
        'assembling' => 'assembling',
        'assembled' => 'assembling-complete',
        'in_delivery' => 'delivering',
        'delivered' => 'complete',
        'cancelled' => 'cancel-other',
        'returned' => 'return',
    ],

    // Маппинг статусов заказов (Inbound: RetailCRM -> System)
    // Позволяет мапить несколько статусов CRM в один системный
    'order_statuses_inbound' => [
        // New
        'new' => 'created',

        // Approval (все этапы согласования считаем за created, пока не начнется сборка)
        'availability-confirmed' => 'created',
        'offer-analog' => 'created',
        'ready-to-wait' => 'created',
        'waiting-for-arrival' => 'created',
        'client-confirmed' => 'created',
        'prepayed' => 'created',

        // Assembling
        'send-to-assembling' => 'assembling',
        'assembling' => 'assembling',
        'assembling-complete' => 'assembled', // Укомплектован = Собран

        // Delivery
        'send-to-delivery' => 'in_delivery',
        'delivering' => 'in_delivery',
        'redirect' => 'in_delivery',
        'ready-for-self-pickup' => 'assembled', // Готов к выдаче = Собран (для самовывоза)
        'arrived-in-pickup-point' => 'in_delivery', // Прибыл в ПВЗ - считаем в процессе доставки до клиента

        // Complete
        'complete' => 'delivered',
        'partially-completed' => 'delivered',

        // Cancel / Return
        'no-call' => 'cancelled',
        'no-product' => 'cancelled',
        'already-buyed' => 'cancelled',
        'delyvery-did-not-suit' => 'cancelled',
        'prices-did-not-suit' => 'cancelled',
        'cancel-other' => 'cancelled',
        'return' => 'cancelled',
    ],

    // Маппинг методов доставки
    'delivery_methods' => [
        'courier' => 'courier',      // Курьер
        'pickup' => 'self-delivery', // Самовывоз
        'cdek' => 'cdek',           // СДЭК
        'russian_post' => 'russian-post', // Почта России
    ],

    // Настройки пулинга (в минутах)
    'polling' => [
        'products_interval' => (int) env('POLLING_PRODUCTS_INTERVAL', 5),
        'categories_interval' => (int) env('POLLING_CATEGORIES_INTERVAL', 30),
        'shops_interval' => (int) env('POLLING_SHOPS_INTERVAL', 60),
        'collectors_interval' => (int) env('POLLING_COLLECTORS_INTERVAL', 60),
        'couriers_interval' => (int) env('POLLING_COURIERS_INTERVAL', 60),
    ],

    // Настройки справочников
    'dictionaries' => [
        'collectors' => env('RETAILCRM_DICT_COLLECTORS', 'collectors'),
    ],
];
