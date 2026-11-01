<?php

declare(strict_types=1);

return [

    'email' => [

        /*
         * Шаблон ссылки на заказ для письма. Подстановка {id}.
         * У storefront_back в конфиге есть только APP_URL — адрес самого API,
         * домена витрины модуль не знает, а push-овый deep_link (order/17)
         * в письме бесполезен. Не задан — письмо уходит без кнопки.
         */
        'order_url_template' => env('NOTIFICATION_EMAIL_ORDER_URL'),

    ],

];
