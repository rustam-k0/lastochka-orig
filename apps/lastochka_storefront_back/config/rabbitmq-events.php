<?php

return [
    'enabled' => env('RABBITMQ_EVENTS_ENABLED', true),

    'connection' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    'exchanges' => [
        'default' => [
            'name' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'type' => 'topic',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    'source' => env('RABBITMQ_SOURCE', 'storefront'),

    /*
    |--------------------------------------------------------------------------
    | Source Timezone
    |--------------------------------------------------------------------------
    |
    | Таймзона внешнего издателя для меток event_timestamp БЕЗ явного офсета.
    | Пусто — метка читается в таймзоне приложения (поведение по умолчанию).
    | Задавать, когда издатель живёт в другой зоне: иначе момент уезжает на
    | разницу зон, а timestamp guard считает событие «из будущего» и глушит
    | всё, что придёт следом (mendev: 1С отдаёт красноярское время, сдвиг +4ч).
    | На метки с явным офсетом (`Z`, `+03:00`) не влияет.
    |
    */

    'source_timezone' => env('RABBITMQ_SOURCE_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Future Timestamp Tolerance
    |--------------------------------------------------------------------------
    |
    | Допуск в секундах на расхождение часов издателя и приёмника. Метка,
    | опережающая now() больше чем на этот допуск, схлопывается до now(),
    | чтобы не заблокировать последующие события этого типа.
    |
    */

    'future_timestamp_tolerance' => (int) env('RABBITMQ_FUTURE_TIMESTAMP_TOLERANCE', 60),
    'shop_code' => env('RABBITMQ_SHOP_CODE', 'default'),

    // Bulk-запись цен/остатков store_products в сетевом режиме: простые позиции
    // (без карточных полей кванта) пишутся массово через upsert, минуя per-item
    // трансформер. По умолчанию выключен (безопасный rollout). shadow — сверка.
    'bulk_price_batch' => env('RABBITMQ_BULK_PRICE_BATCH', false),
    'bulk_price_batch_shadow' => env('RABBITMQ_BULK_PRICE_BATCH_SHADOW', false),

    'entities' => [
        'customers' => [
            'enabled' => true, // Публикуем
            'model' => \App\Models\User::class,
            'transformer' => \App\RabbitMQ\Transformers\CustomerEventTransformer::class,
            'events' => ['created', 'updated'],
            'routing_key_prefix' => 'customer',
            'exchange' => 'default',
            'condition' => \App\RabbitMQ\Conditions\CustomerHasPhoneCondition::class,
            // Входящие события от admin_back
            'routing_key_patterns' => [
                'customer.created',
                'customer.updated',
                'customer.deleted',
            ],
            'global_scope' => true,
        ],

        'shops' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Store::class,
            'transformer' => \App\RabbitMQ\Transformers\StoreEventTransformer::class,
            'relations' => ['schedules'],
            'routing_key_patterns' => [
                'shop.created',
                'shop.updated',
                'shop.deleted',
            ],
        ],

        'cities' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\City::class,
            'transformer' => \App\RabbitMQ\Transformers\CityEventTransformer::class,
            'relations' => [],
            'routing_key_patterns' => [
                'city.created',
                'city.updated',
                'city.deleted',
            ],
            'global_scope' => true, // Города — глобальные справочники
        ],

        'categories' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Category::class,
            'transformer' => \App\RabbitMQ\Transformers\CategoryEventTransformer::class,
            'relations' => ['parent', 'stores'],
            'routing_key_patterns' => [
                'category.created',
                'category.updated',
                'category.deleted',
                'category.shop_detached',
            ],
            'global_scope' => true, // Категории — глобальные справочники
        ],

        'products' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Product::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductEventTransformer::class,
            'relations' => ['store', 'category', 'images', 'attributes'],
            'routing_key_patterns' => [
                'product.created',
                'product.updated',
                'product.deleted',
                'product.stock.changed',
                'product.stock_updated',
                'product.price_updated',
                'product.configurable.created',
                'product.configurable.updated',
                'product.configurable.deleted',
            ],
        ],

        'stories' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Story::class,
            'transformer' => \App\RabbitMQ\Transformers\StoryEventTransformer::class,
            'relations' => ['images'],
            'routing_key_patterns' => [
                'story.created',
                'story.updated',
                'story.deleted',
            ],
        ],

        'brands' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Brand::class,
            'transformer' => \App\RabbitMQ\Transformers\BrandEventTransformer::class,
            'relations' => [],
            'global_scope' => true, // Бренды — глобальные справочники
            'routing_key_patterns' => [
                'brand.created',
                'brand.updated',
                'brand.deleted',
            ],
        ],

        'faqs' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Faq::class,
            'transformer' => \App\RabbitMQ\Transformers\FaqEventTransformer::class,
            'relations' => [],
            'global_scope' => true, // FAQ — глобальный справочник
            'routing_key_patterns' => [
                'faq.created',
                'faq.updated',
                'faq.deleted',
            ],
        ],

        'vacancies' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Vacancy::class,
            'transformer' => \App\RabbitMQ\Transformers\VacancyEventTransformer::class,
            'relations' => [],
            'global_scope' => true, // Вакансии — глобальный справочник
            'routing_key_patterns' => [
                'vacancy.created',
                'vacancy.updated',
                'vacancy.deleted',
            ],
        ],

        'pages' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Page::class,
            'transformer' => \App\RabbitMQ\Transformers\PageEventTransformer::class,
            'relations' => [],
            'global_scope' => true, // Страницы — глобальный справочник
            'routing_key_patterns' => [
                'page.created',
                'page.updated',
                'page.deleted',
            ],
        ],

        'merchant_sales' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Promotion::class,
            'transformer' => \App\RabbitMQ\Transformers\PromotionEventTransformer::class,
            'relations' => ['store', 'products'],
            'routing_key_patterns' => [
                'merchant_sale.created',
                'merchant_sale.updated',
                'merchant_sale.deleted',
            ],
        ],

        'merchant_product_groups' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\ProductGroup::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductGroupEventTransformer::class,
            'relations' => ['store', 'items', 'products'],
            'routing_key_patterns' => [
                'merchant_product_group.created',
                'merchant_product_group.updated',
                'merchant_product_group.deleted',
            ],
        ],

        'product_group_filters' => [
            'enabled' => false, // Только consume
            'model' => \App\Models\ProductGroupFilter::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductGroupFilterEventTransformer::class,
            'relations' => ['values'],
            'routing_key_patterns' => [
                'product_group_filter.created',
                'product_group_filter.updated',
                'product_group_filter.deleted',
            ],
            'global_scope' => true,
        ],

        'merchant_banners' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Banner::class,
            'transformer' => \App\RabbitMQ\Transformers\BannerEventTransformer::class,
            'relations' => ['store', 'linkProduct', 'linkCategory'],
            'routing_key_patterns' => [
                'merchant_banner.created',
                'merchant_banner.updated',
                'merchant_banner.deleted',
            ],
        ],

        'merchant_promocodes' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Promocode::class,
            'transformer' => \App\RabbitMQ\Transformers\PromocodeEventTransformer::class,
            'relations' => ['store'],
            'routing_key_patterns' => [
                'merchant_promocode.created',
                'merchant_promocode.updated',
                'merchant_promocode.deleted',
            ],
        ],

        'store_reviews' => [
            'enabled' => true, // Публикуем
            'model' => \App\Models\StoreReview::class,
            'transformer' => \App\RabbitMQ\Transformers\StoreReviewEventTransformer::class,
            'relations' => ['store', 'user'],
            'events' => ['created', 'updated'],
            'routing_key_prefix' => 'merchant_review',
            'exchange' => 'default',
            'condition' => \App\RabbitMQ\Conditions\ReviewHasUserCondition::class,
            // Для входящих событий
            'routing_key_patterns' => [
                'merchant_review.created',
                'merchant_review.updated',
                'merchant_review.deleted',
            ],
        ],

        'product_reviews' => [
            'enabled' => true, // Публикуем
            'model' => \App\Models\ProductReview::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductReviewEventTransformer::class,
            'relations' => ['product', 'store', 'user'],
            'events' => ['created', 'updated'],
            'routing_key_prefix' => 'product_review',
            'exchange' => 'default',
            'condition' => \App\RabbitMQ\Conditions\ReviewHasUserCondition::class,
            // Для входящих событий
            'routing_key_patterns' => [
                'product_review.created',
                'product_review.updated',
                'product_review.deleted',
            ],
        ],

        'customer_b2b' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\User::class,
            'transformer' => \App\RabbitMQ\Transformers\CustomerB2bStatusTransformer::class,
            'routing_key_patterns' => [
                'customer.b2b_status_changed',
            ],
            'global_scope' => true,
        ],

        'orders' => [
            'enabled' => true,
            'model' => \App\Models\Order::class,
            'transformer' => \App\RabbitMQ\Transformers\OrderEventTransformer::class,
            'relations' => ['user', 'store', 'items', 'address', 'promocode', 'yandexClaim'],
            'events' => ['created'],
            'delay_seconds' => 5, // Ждём создания relations (items, address, promocode)
            'routing_key_prefix' => 'order',
            'exchange' => 'default',
            // Для входящих событий от CRM
            'routing_key_patterns' => [
                'order.status.changed',
                'order.status_changed',
                'order.updated',
            ],
            // Черновик (неподтверждённая онлайн-оплата) в платформу не уезжает —
            // событие за него выпустит FinalizeDraftOrderAction после холда.
            'condition' => \App\RabbitMQ\Conditions\OrderNotDraftCondition::class,
        ],

        'order_reviews' => [
            'enabled' => true, // Публикуем
            'model' => \App\Models\OrderReview::class,
            'transformer' => \App\RabbitMQ\Transformers\OrderReviewEventTransformer::class,
            'relations' => ['order', 'user'],
            'events' => ['created', 'updated'],
            'routing_key_prefix' => 'order_review',
            'exchange' => 'default',
            'condition' => \App\RabbitMQ\Conditions\ReviewHasUserCondition::class,
        ],

        'integration_configs' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\IntegrationConfig::class,
            'transformer' => \App\RabbitMQ\Transformers\IntegrationConfigEventTransformer::class,
            'routing_key_patterns' => [
                'integration_config.created',
                'integration_config.updated',
            ],
            'global_scope' => true,
        ],

        'announcements' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\Announcement::class,
            'transformer' => \App\RabbitMQ\Transformers\AnnouncementEventTransformer::class,
            'relations' => ['promocode'],
            'routing_key_patterns' => [
                'announcement.created',
                'announcement.updated',
                'announcement.deleted',
            ],
        ],

        'announcement_notifications' => [
            'enabled' => true, // Включаем для получения уведомлений
            'model' => \App\Models\AnnouncementNotification::class, // Пустая модель для совместимости
            'transformer' => \App\RabbitMQ\Transformers\AnnouncementNotificationTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\AnnouncementNotificationHandler::class,
            'routing_key_patterns' => [
                'announcement.notification.notification_sent',
            ],
        ],

        'admin_settings' => [
            'enabled' => false, // Только consume, не publish
            'model' => \App\Models\StorefrontSetting::class,
            'transformer' => \App\RabbitMQ\Transformers\AdminSettingEventTransformer::class,
            'routing_key_patterns' => [
                'admin_setting.created',
                'admin_setting.updated',
                'admin_setting.deleted',
                'admin_settings.created',
                'admin_settings.updated',
                'admin_settings.deleted',
            ],
            'global_scope' => true, // Настройки — глобальные для всего storefront
        ],

        'preorders' => [
            'enabled' => true,
            'model' => \App\Models\Preorder::class,
            'transformer' => \App\RabbitMQ\Transformers\PreorderEventTransformer::class,
            'relations' => ['user', 'product', 'store'],
            'events' => ['created', 'updated'],
            'routing_key_prefix' => 'preorder',
            'routing_key_patterns' => [
                'preorder.created',
                'preorder.updated',
            ],
        ],

        'product_issue_reports' => [
            'enabled' => true,
            'model' => \App\Models\ProductIssueReport::class,
            'transformer' => \App\RabbitMQ\Transformers\ProductIssueReportEventTransformer::class,
            'relations' => ['user', 'product', 'store'],
            'events' => ['created'],
            'routing_key_prefix' => 'product_issue_report',
            'routing_key_patterns' => [
                'product_issue_report.created',
            ],
        ],

        'bonus_operations' => [
            'enabled' => true,
            'model' => \App\Models\BonusTransaction::class,
            'transformer' => \App\RabbitMQ\Transformers\BonusOperationEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\BonusOperationHandler::class,
            'events' => [], // publish manually via CustomEventPublisher
            'routing_key_prefix' => 'bonus_operation',
            'exchange' => 'default',
            'routing_key_patterns' => [
                'bonus_operation.created',
            ],
        ],

        // Команда на ручную операцию с баллами из админки (admin_back).
        // Только consume: сущности в БД у команды нет, применение идёт через handler.
        // enabled=false: model совпадает с bonus_operations (BonusTransaction),
        // включение observer'а тут задублировало бы регистрацию eloquent-листенеров
        // на ту же модель — bonus_operations уже их регистрирует, а events здесь
        // пустой, publish эта сущность всё равно никогда не делает.
        'bonus_operation_requests' => [
            'enabled' => false,
            'model' => \App\Models\BonusTransaction::class,
            'transformer' => \App\RabbitMQ\Transformers\ManualBonusOperationTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\ManualBonusOperationHandler::class,
            'events' => [],
            'routing_key_patterns' => [
                'bonus_operation.requested',
            ],
        ],

        'loyalty_cards' => [
            'enabled' => true,
            'model' => \App\Models\User::class,
            // publish — вручную через EventOutbox (LoyaltyCardService), consume —
            // через handler. Transformer используется ТОЛЬКО для validate() входящего
            // события: EventConsumer::processEvent() вызывает validate() ДО роутинга
            // на handler, поэтому requiredFields обязаны матчиться с реальным payload'ом
            // loyalty_card.confirmed ({event_id, event_type, event_source, loyalty_id,
            // card_number}), а не с CustomerEventTransformer (требует phone).
            'transformer' => \App\RabbitMQ\Transformers\LoyaltyCardEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\LoyaltyCardConfirmedHandler::class,
            'events' => [], // publish manually via EventOutbox
            'routing_key_prefix' => 'loyalty_card',
            'exchange' => 'default',
            'routing_key_patterns' => [
                'loyalty_card.confirmed',
            ],
        ],

        // B2C поведенческие события от мобилки (LASTOCHKI-JAMI-11659).
        // Publish — вручную через EventOutbox (AnalyticsIngestService),
        // consume отсутствует. Entity нужна, чтобы publish-outbox воркер
        // подхватывал записи entity_type=b2c_events (он итерирует ключи
        // config.entities). Routing: b2c.<event_type>, потребитель —
        // lastochka_analytics_back.
        'b2c_events' => [
            'enabled' => true,
            'model' => \App\Models\User::class,
            // Transformer-заглушка: ModelEventObserver требует ключ transformer
            // у каждой enabled entity, даже с events => [].
            'transformer' => \App\RabbitMQ\Transformers\B2cEventTransformer::class,
            'events' => [], // publish manually via EventOutbox
            'routing_key_prefix' => 'b2c',
            'exchange' => 'default',
        ],

        'barcode_prefixes' => [
            'enabled' => true,
            'model' => \App\Models\StorefrontSetting::class,
            // consume — через handler. Transformer используется ТОЛЬКО для validate()
            // входящего события: EventConsumer::processEvent() вызывает validate() ДО
            // роутинга на handler, поэтому requiredFields обязаны матчиться с реальным
            // payload'ом barcode_prefixes.updated ({event_id, event_type, event_source,
            // type, value}), а не с AdminSettingEventTransformer (требует source, key).
            'transformer' => \App\RabbitMQ\Transformers\BarcodePrefixEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\BarcodePrefixHandler::class,
            'events' => [],
            'routing_key_prefix' => 'barcode_prefixes',
            'exchange' => 'default',
            'routing_key_patterns' => [
                'barcode_prefixes.updated',
            ],
        ],

        'product_batches' => [
            'enabled' => true,
            'model' => \App\Models\Product::class,
            // consume только через handler. Transformer используется ТОЛЬКО для
            // validate() входящего конверта (EventConsumer вызывает validate() ДО
            // роутинга на handler) — паттерн barcode_prefixes/loyalty_cards.
            // Батч эквивалентен N одиночным product-событиям с общим конвертом
            // (docs/specs контракт 1С, раздел 6).
            'transformer' => \App\RabbitMQ\Transformers\ProductBatchEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\ProductBatchHandler::class,
            'events' => [], // публикация батчей платформой не предполагается
            'routing_key_prefix' => 'product',
            'exchange' => 'default',
            'routing_key_patterns' => [
                'product.price_batch_updated',
                'product.stock_batch_updated',
                'product.batch_updated',
            ],
        ],
    ],

    'inbound_queues' => [
        'shops' => env('RABBITMQ_QUEUE_SHOPS', 'storefront_shops'),
        'cities' => env('RABBITMQ_QUEUE_CITIES', 'storefront_cities'),
        'categories' => env('RABBITMQ_QUEUE_CATEGORIES', 'storefront_categories'),
        'products' => env('RABBITMQ_QUEUE_PRODUCTS', 'storefront_products'),
        'product_batches' => env('RABBITMQ_QUEUE_PRODUCT_BATCHES', 'storefront_product_batches'),
        'stories' => env('RABBITMQ_QUEUE_STORIES', 'storefront_stories'),
        'brands' => env('RABBITMQ_QUEUE_BRANDS', 'storefront_brands'),
        'faqs' => env('RABBITMQ_QUEUE_FAQS', 'storefront_faqs'),
        'vacancies' => env('RABBITMQ_QUEUE_VACANCIES', 'storefront_vacancies'),
        'pages' => env('RABBITMQ_QUEUE_PAGES', 'storefront_pages'),
        'merchant_sales' => env('RABBITMQ_QUEUE_PROMOTIONS', 'storefront_promotions'),
        'merchant_product_groups' => env('RABBITMQ_QUEUE_PRODUCT_GROUPS', 'storefront_product_groups'),
        'merchant_banners' => env('RABBITMQ_QUEUE_BANNERS', 'storefront_banners'),
        'merchant_promocodes' => env('RABBITMQ_QUEUE_PROMOCODES', 'storefront_promocodes'),
        'announcements' => env('RABBITMQ_QUEUE_ANNOUNCEMENTS', 'storefront_announcements'),
        'announcement_notifications' => env('RABBITMQ_QUEUE_ANNOUNCEMENT_NOTIFICATIONS', 'storefront_announcement_notifications'),
        'store_reviews' => env('RABBITMQ_QUEUE_STORE_REVIEWS', 'storefront_store_reviews'),
        'product_reviews' => env('RABBITMQ_QUEUE_PRODUCT_REVIEWS', 'storefront_product_reviews'),
        'orders' => env('RABBITMQ_QUEUE_ORDERS', 'storefront_orders'),
        'customers' => env('RABBITMQ_QUEUE_CUSTOMERS', 'storefront_customers'),
        'customer_b2b' => env('RABBITMQ_QUEUE_CUSTOMER_B2B', 'storefront_customer_b2b'),
        'integration_configs' => env('RABBITMQ_QUEUE_INTEGRATION_CONFIGS', 'storefront_integration_configs'),
        'admin_settings' => env('RABBITMQ_QUEUE_ADMIN_SETTINGS', 'storefront_admin_settings'),
        'product_group_filters' => env('RABBITMQ_QUEUE_PRODUCT_GROUP_FILTERS', 'storefront_product_group_filters'),
        'preorders' => env('RABBITMQ_QUEUE_PREORDERS', 'storefront_preorders'),
        'bonus_operations' => env('RABBITMQ_QUEUE_BONUS_OPERATIONS', 'storefront_bonus_operations'),
        'bonus_operation_requests' => env('RABBITMQ_QUEUE_BONUS_OPERATION_REQUESTS', 'storefront_bonus_operation_requests'),
        'loyalty_cards' => env('RABBITMQ_QUEUE_LOYALTY_CARDS', 'storefront_loyalty_cards'),
        'barcode_prefixes' => env('RABBITMQ_QUEUE_BARCODE_PREFIXES', 'storefront_barcode_prefixes'),
    ],

    'outbox' => [
        'enabled' => env('RABBITMQ_OUTBOX_ENABLED', true),
        'poll_interval' => 1,
        'batch_size' => 1000,
        'memory_limit' => 128,
        'retention_days' => 7,
    ],

    'consumer' => [
        'prefetch_count' => 1,
        'restart_after' => 1000,
        'memory_limit' => 128,
    ],
];
