<?php

return [
    'enabled' => env('YANDEX_DELIVERY_ENABLED', false),
    'base_url' => env('YANDEX_DELIVERY_BASE_URL', 'https://b2b.taxi.yandex.net'),
    'oauth_token' => env('YANDEX_DELIVERY_OAUTH_TOKEN'),
    'taxi_class' => env('YANDEX_DELIVERY_TAXI_CLASS', 'express'),
    // true = доставка «от подъезда» (расчёт по координатам, дешевле).
    // false = занос «до двери» — требует детального адреса (дом/подъезд),
    // иначе Яндекс возвращает 409 suitable_offer_not_found.
    'skip_door_to_door' => env('YANDEX_DELIVERY_SKIP_DOOR_TO_DOOR', true),
    // Термосумка не требуется ни для одного заказа — обязательное требование
    // сужало пул подходящих курьеров и приводило к 409 suitable_offer_not_found
    // там, где курьеров с термосумкой на express-тарифе просто не было. ALADDIN-11683.
    'cargo_options' => [],
    'max_due_hours' => env('YANDEX_DELIVERY_MAX_DUE_HOURS', 4),
    'check_price_cache_ttl' => env('YANDEX_DELIVERY_PRICE_CACHE_TTL', 300),
    'delivery_methods_cache_ttl' => env('YANDEX_DELIVERY_METHODS_CACHE_TTL', 1800),
    'callback_url' => env('YANDEX_DELIVERY_CALLBACK_URL'),
    'emergency_phone' => env('YANDEX_DELIVERY_EMERGENCY_PHONE'),
    'default_item_weight' => env('YANDEX_DELIVERY_DEFAULT_WEIGHT', 5),
    'default_item_size' => [
        'height' => 0.3,
        'length' => 0.3,
        'width' => 0.3,
    ],
    'timeout' => env('YANDEX_DELIVERY_TIMEOUT', 10),
    'retry_times' => env('YANDEX_DELIVERY_RETRY_TIMES', 2),
];
