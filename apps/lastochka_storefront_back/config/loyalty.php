<?php

return [
    // Дефолтный префикс номера карты (2 символа) — используется, пока 1С
    // не прислала актуальный через обмен префиксами (мастер префиксов — 1С).
    'card_prefix' => env('LOYALTY_CARD_PREFIX', '07'),

    // Ключ в storefront_settings с актуальным префиксом карт от 1С.
    'card_prefix_setting_key' => 'loyalty_card_prefix',

    // Попыток генерации уникального номера до исключения.
    'generate_attempts' => 20,
];
