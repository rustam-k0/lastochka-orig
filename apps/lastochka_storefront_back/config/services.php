<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'delivery_system' => [
        'url' => env('DELIVERY_SYSTEM_URL'),
        'api_key' => env('DELIVERY_SYSTEM_API_KEY'),
        'timeout' => env('DELIVERY_SYSTEM_TIMEOUT', 10),
    ],

    'slots_api' => [
        'base_url' => env('SLOTS_API_BASE_URL'),
        'timeout' => env('SLOTS_API_TIMEOUT', 10),
        'cache_ttl' => env('SLOTS_API_CACHE_TTL', 3600),
    ],

    'geoip' => [
        'base_url' => env('GEOIP_BASE_URL', 'http://ip-api.com'),
        'timeout' => env('GEOIP_TIMEOUT', 5),
        'cache_ttl' => env('GEOIP_CACHE_TTL', 86400),
    ],

    'sms_service' => [
        'driver' => env('SMS_SERVICE_DRIVER', 'otp'),
    ],

    /*
    | Название сервиса, которое подставляется в текст SMS с кодом.
    | Приоритет: настройка `sms_service_name` из админки → `admin_title`
    | → это значение → APP_NAME. Операторы блокируют SMS без названия.
    */
    'sms' => [
        'service_name' => env('SMS_SERVICE_NAME'),
    ],

    'auth' => [
        'test_phones' => env('AUTH_TEST_PHONES')
            ? collect(explode(',', env('AUTH_TEST_PHONES')))
                ->mapWithKeys(function (string $pair) {
                    [$phone, $code] = explode(':', $pair);
                    return [$phone => $code];
                })
                ->all()
            : [],
        // Длина SMS-кода входа (4–6), по умолчанию 6; код звонка всегда 4 цифры.
        'sms_code_length' => env('AUTH_SMS_CODE_LENGTH'),
    ],

    'smsaero' => [
        'login' => env('SMSAERO_LOGIN'),
        'password' => env('SMSAERO_PASSWORD'),
        'sign' => env('SMSAERO_SIGN', 'SMS Aero'),
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'database_url' => env('FIREBASE_DATABASE_URL'),
    ],

    'yookassa' => [
        // Mock-режим для стенда: тестовые доступы ЮKassa подключены, но вебхук
        // на него настроить нельзя. Запросы в ЮKassa продолжают уходить, но
        // подтверждения не ждём — холд считается подтверждённым, списание и
        // возврат прошедшими. См. App\Support\Payment\PaymentMockMode:
        // на боевых кредах (mode=live) режим не активируется никогда.
        'mock' => filter_var(env('YOOKASSA_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN),

        // Дефолтный адрес возврата после оплаты: используется, когда клиент
        // не передал свой (`returnUrl` в запросе создания заказа, повторной
        // оплаты, привязки карты). Относительное значение склеивается с APP_URL.
        'return_url' => env('YOOKASSA_RETURN_URL', '/'),

        // Белый список доменов, на которые разрешено возвращать пользователя
        // после оплаты. Без него любой мог бы подставить свой адрес в запрос
        // и увести клиента после оплаты на фишинговую страницу.
        // Формат: «store.example.ru,*.example.ru» (wildcard матчит и сам домен).
        // Хост дефолтного return_url и APP_URL разрешены всегда.
        'allowed_return_hosts' => env('YOOKASSA_ALLOWED_RETURN_HOSTS', ''),

        // Дополнительные схемы для диплинков мобильного приложения,
        // например «mendevapp». https и схема APP_URL разрешены всегда.
        'allowed_return_schemes' => env('YOOKASSA_ALLOWED_RETURN_SCHEMES', ''),

        // Фискализация (54-ФЗ). Применяется, когда к магазину YooKassa
        // подключена онлайн-касса — без объекта receipt любой POST /payments
        // отклоняется с "Receipt is missing or illegal".
        // Дефолт true: на проде с подключённой кассой выключение чека приведёт
        // к 422. Переопределяется галочкой send_receipt в настройках интеграции.
        'send_receipt' => filter_var(env('YOOKASSA_SEND_RECEIPT', true), FILTER_VALIDATE_BOOLEAN),
        // vat_code: 1 — без НДС, 2 — 0%, 3 — 10%, 4 — 20%, 5 — 10/110, 6 — 20/120,
        // 11 — 22%, 12 — 22/122 (базовая ставка РФ с 01.01.2026, ФЗ №425-ФЗ).
        // Применяется только к позициям без собственного vat_rate; дефолт 4
        // оставлен намеренно — переключение на 11 меняет чеки таких позиций
        // и делается осознанно через YOOKASSA_VAT_CODE или настройку интеграции.
        'vat_code' => (int) env('YOOKASSA_VAT_CODE', 4),
        // tax_system_code: обязателен, только если в кассе указано >1 СНО.
        // null → не передавать (YooKassa подставит единственную СНО сама).
        'tax_system_code' => env('YOOKASSA_TAX_SYSTEM_CODE') !== null
            ? (int) env('YOOKASSA_TAX_SYSTEM_CODE')
            : null,
        // payment_mode/payment_subject для позиций товаров. Для двухстадийной
        // оплаты (холд → списание при сборке) — полная предоплата.
        'payment_mode' => env('YOOKASSA_PAYMENT_MODE', 'full_prepayment'),
        'payment_subject' => env('YOOKASSA_PAYMENT_SUBJECT', 'commodity'),

        // Реквизиты продавца для отображения на чеке в приложении.
        // ЮKassa их в GET /receipts не возвращает — берём из настроек.
        'seller' => [
            'name' => env('YOOKASSA_SELLER_NAME'),
            'inn' => env('YOOKASSA_SELLER_INN'),
        ],
    ],

    'yandex_geocoder' => [
        'api_key' => env('YANDEX_GEOCODER_API_KEY'),
    ],

    'internal_api' => [
        'token' => env('INTERNAL_API_TOKEN'),
    ],

    'atol' => [
        'base_url_test' => env('ATOL_BASE_URL_TEST', 'https://testonline.atol.ru/possystem/v4'),
        'base_url_live' => env('ATOL_BASE_URL_LIVE', 'https://online.atol.ru/possystem/v4'),
        'base_url_test_v5' => env('ATOL_BASE_URL_TEST_V5', 'https://testonline.atol.ru/possystem/v5'),
        'base_url_live_v5' => env('ATOL_BASE_URL_LIVE_V5', 'https://online.atol.ru/possystem/v5'),
        'callback_url' => env('ATOL_CALLBACK_URL', env('APP_URL').'/api/v1/webhooks/atol'),
        'timeout' => (int) env('ATOL_TIMEOUT', 15),
    ],
];
