<?php

return [
    'host' => env('RABBITMQ_HOST', 'localhost'),
    'port' => env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // Единый exchange для всех событий
    'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
    'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),

    // Очереди для обработки событий
    // Все очереди биндятся к одному exchange с разными routing keys
    'queues' => [
        'products' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            'queue' => 'retailbridge.products',
            'routing_keys' => [
                'product.created',
                'product.updated',
                'product.deleted',
                'product.stock.changed',
                'product.stock_updated',
            ],
        ],
        'categories' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            'queue' => 'retailbridge.categories',
            'routing_keys' => [
                'category.created',
                'category.updated',
                'category.deleted',
            ],
        ],
        'orders' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            'queue' => 'retailbridge.orders',
            'routing_keys' => [
                'order.created',
                'order.updated',
                'order.status.changed',
                'order.status_changed',
                'order.cancelled',
                'order.paid',
                'order.refunded',
                'order.delivery.updated',
            ],
        ],
        'customers' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            'queue' => 'retailbridge.customers',
            'routing_keys' => [
                'customer.created',
                'customer.updated',
            ],
        ],
        'delivery_addresses' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            'queue' => 'retailbridge.delivery_addresses',
            'routing_keys' => [
                'customer.address.created',
                'customer.address.updated',
                'customer.address.deleted',
            ],
        ],
//        'users' => [
//            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
//            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
//            'queue' => 'retailbridge.users',
//            'routing_keys' => [
//                'user.created',
//                'user.updated',
//                'user.deleted',
//            ],
//        ],
        'shops' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
            'queue' => 'retailbridge.shops',
            'routing_keys' => [
                'shop.created',
                'shop.updated',
                'shop.deleted',
                'shop.stock.changed',
            ],
        ],
    ],

    // Настройки публикации событий
    // Все публикуют в единый exchange
    'publishers' => [
        'products' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        ],
        'categories' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        ],
        'orders' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        ],
        'customers' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        ],
//        'users' => [
//            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
//            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
//        ],
        'shops' => [
            'exchange' => env('RB_EXCHANGE_NAME', 'retailbridge'),
            'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        ],
    ],
];
