<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Events Package Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable the entire package. When disabled, no events will be
    | published or consumed.
    |
    */

    'enabled' => env('RABBITMQ_EVENTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Connection Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your RabbitMQ server connection details.
    |
    */

    'connection' => [
        'host' => env('RABBITMQ_HOST', 'localhost'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Exchanges
    |--------------------------------------------------------------------------
    |
    | Define your RabbitMQ exchanges. The 'default' exchange is used for
    | all events unless specified otherwise in entity configuration.
    |
    */

    'exchanges' => [
        'default' => [
            'name' => env('RABBITMQ_EXCHANGE', 'app_events'),
            'type' => 'topic',
            'durable' => true,
            'auto_delete' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Identifier
    |--------------------------------------------------------------------------
    |
    | Unique identifier for this application/microservice. Used in event
    | payloads to identify the source of events.
    |
    */

    'source' => env('RABBITMQ_SOURCE', 'app'),

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

    /*
    |--------------------------------------------------------------------------
    | Shop Code
    |--------------------------------------------------------------------------
    |
    | Unique identifier for the shop/tenant. Used in event payloads.
    |
    */

    'shop_code' => env('RABBITMQ_SHOP_CODE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Shared Database Sources
    |--------------------------------------------------------------------------
    |
    | Источники (RABBITMQ_SOURCE) сервисов, которые работают с ТОЙ ЖЕ базой,
    | что и это приложение: admin_back, delivery_back и purchase_back живут в
    | одной БД курьерки. Их события — эхо уже применённых изменений, а не
    | данные извне: применять их повторно нечего, а вреда много (MID-265 —
    | синтетическая позиция замены `{id}_origin` из payload delivery_back
    | материализовалась в курьерке как настоящий товар в заказе).
    |
    | Пустой список — поведение как раньше (все входящие события обрабатываются).
    |
    */

    'shared_db_sources' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RABBITMQ_SHARED_DB_SOURCES', 'delivery,purchase'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Bulk price/stock batch write (feature flag)
    |--------------------------------------------------------------------------
    |
    | Когда включён — штучные (не-квантовые) позиции price_batch/stock_batch
    | пишутся массово, минуя per-item transformer-путь. Весовые/квантовые
    | остаются на полном пути. По умолчанию выключен (безопасный rollout).
    |
    */

    'bulk_price_batch' => env('RABBITMQ_BULK_PRICE_BATCH', false),

    // Shadow: пишем по-старому (per-item), но считаем bulk-результат и сверяем
    // с фактически записанным, логируя расхождения. Для безопасного выката:
    // включить shadow, убедиться по логам что diverged=0, затем bulk_price_batch=on.
    'bulk_price_batch_shadow' => env('RABBITMQ_BULK_PRICE_BATCH_SHADOW', false),

    /*
    |--------------------------------------------------------------------------
    | Entity Configurations
    |--------------------------------------------------------------------------
    |
    | Configure which models should publish events and how they should be
    | transformed. Each entity can have its own transformer, routing rules,
    | and conditions.
    |
    | Example:
    | 'categories' => [
    |     'enabled' => true,
    |     'model' => \App\Models\Category::class,
    |     'transformer' => \App\Transformers\CategoryEventTransformer::class,
    |     'relations' => ['shopCategories.shop'],
    |     'events' => ['created', 'updated', 'deleted'],
    |     'routing_key_prefix' => 'category',
    |     'exchange' => 'default',
    |     'condition' => \App\RabbitMQ\Conditions\CategoryHasShopsCondition::class,
    |     'scout_sync' => false,
    | ],
    |
    */

    'entities' => [
        'categories' => [
            'enabled' => true,
            'model' => \App\Models\Category::class,
            'transformer' => \App\Transformers\CategoryEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => ['siteCatalogImg', 'siteSquareImg', 'backgroundImg', 'shopCategories.shop'],
            'relation_method' => 'shopCategories',
            'nested_relation' => 'shop',
            'routing_key_prefix' => 'category',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
              'category.created',
              'category.updated',
              'category.deleted',
              'category.shop_detached',
            ],
        ],

        'products' => [
            'enabled' => true,
            'model' => \App\Models\Product::class,
            'transformer' => \App\Transformers\ProductEventTransformer::class,

            // Стандартные Eloquent события для create и delete
            'events' => ['created', 'deleted'],

            // Прослушивание кастомного события ProductUpdated для update
            // Это событие триггерится в ProductUpdate мутации после всех изменений
            // (Product + Prices + Attributes + Parameters + Images)
            'listen_events' => [
                \App\Events\ProductUpdated::class,
            ],
            'event_type' => 'updated',  // Тип события для listen_events
            'model_property' => 'product',  // Свойство с моделью в ProductUpdated

            'relations' => [
                'img',
                'images',
                'prices.shop',
                'remains',
                'categories',
                'attributes.attribute',
                'supplements.values',
                'documents',
                'children',
            ],
            'routing_key_prefix' => 'product',
            'exchange' => 'default',
            'delay_seconds' => 2,
            'routing_key_patterns' => [
              'product.created',
              'product.updated',
              'product.deleted',
              'product.stock.changed',
              'product.price_updated', // Кастомное событие обновления цены
              'product.stock_updated', // Кастомное событие обновления остатков
              'product.configurable.created',
              'product.configurable.updated',
              'product.configurable.deleted',
            ],
        ],

        'product_batches' => [
            'enabled' => true,
            'model' => \App\Models\Product::class,
            // consume только через handler. Transformer используется ТОЛЬКО для
            // validate() входящего конверта (EventConsumer вызывает validate() ДО
            // роутинга на handler). Батч эквивалентен N одиночным product-событиям
            // с общим конвертом (docs/specs контракт 1С, раздел 6).
            'transformer' => \App\Transformers\ProductBatchEventTransformer::class,
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

        'orders' => [
            'enabled' => true,
            'model' => \App\Models\Order::class,
            'transformer' => \App\Transformers\OrderEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => ['orderItems.product', 'orderItems.originProduct', 'client', 'shop', 'clientInfo'],
            'routing_key_prefix' => 'order',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
              'order.created',
              'order.updated',
              'order.deleted',
              'order.status.changed',
              'order.status_changed',
              'order.order_cancelled',
              'order.order_paid',
              'order.order_payment_held',
              'order.order_payment_failed',
              'order.order_surcharge_required',
              'order.order_refunded',
              'order.order_supplemented',
              'order.order_yandex_updated',
            ],
            'condition' => \App\RabbitMQ\Conditions\OrderNotSubOrderCondition::class,
        ],

        'customers' => [
            'enabled' => true,
            'model' => \App\Models\Client::class,
            'transformer' => \App\Transformers\ClientEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => [],
            'routing_key_prefix' => 'customer',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'customer.created',
                'customer.updated',
                'customer.deleted',
            ],
        ],

        // Факты и отказы по операциям с баллами из storefront_back.
        // Только consume: команду публикуем вручную через EventOutbox
        // (ClientBonusService), CRUD модели в шину не уходит.
        'client_bonus_transactions' => [
            'enabled' => false,
            'model' => \App\Models\ClientBonusTransaction::class,
            'transformer' => \App\Transformers\ClientBonusOperationEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\ClientBonusOperationHandler::class,
            'events' => [],
            'routing_key_patterns' => [
                'bonus_operation.created',
                'bonus_operation.rejected',
            ],
        ],

        'addresses' => [
            'enabled' => true,
            'model' => \App\Models\DeliveryAddress::class,
            'transformer' => \App\Transformers\ClientAddressEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => ['deliveryInfo.client'],
            'routing_key_prefix' => 'customer.address',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'customer.address.created',
                'customer.address.updated',
                'customer.address.deleted',
            ],
        ],

        'cities' => [
            'enabled' => true,
            'model' => \App\Models\City::class,
            'transformer' => \App\Transformers\CityEventTransformer::class,
            'relations' => [],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'city',
            'delay_seconds' => 1,
        ],

        'shops' => [
          'enabled' => true,
          'model' => \App\Models\Shop::class,
          'transformer' => \App\Transformers\ShopEventTransformer::class,
          'relations' => ['workingHours', 'zones', 'city'],
          'events' => ['created', 'updated', 'deleted'],
          'routing_key_prefix' => 'shop',
          'delay_seconds' => 3,
        ],

//        'users' => [
//            'enabled' => true,
//            'model' => \App\Models\User::class,
//            'transformer' => \App\Transformers\UserEventTransformer::class,
//            'relations' => ['shops', 'roles'],
//            'events' => ['created', 'updated', 'deleted'],
//            'routing_key_prefix' => 'user',
//            'exchange' => 'default',
//            'delay_seconds' => 2,
//            'routing_key_patterns' => [
//                'user.created',
//                'user.updated',
//                'user.deleted',
//            ],
//        ],

        // Сотрудники из 1С (MID-306): только consume, платформа не публикует.
        // global_scope: сотрудник не принадлежит магазину конверта, связь
        // GUID → users.id ищется без shop_code.
        'employees' => [
            'enabled' => false,
            'model' => \App\Models\User::class,
            'transformer' => \App\Transformers\EmployeeEventTransformer::class,
            'events' => [],
            'routing_key_prefix' => 'employee',
            'exchange' => 'default',
            'global_scope' => true,
            'routing_key_patterns' => [
                'employee.created',
                'employee.updated',
            ],
        ],

        // Пачка сотрудников из 1С (MID-306): только consume через handler.
        'employee_batches' => [
            'enabled' => false,
            'model' => \App\Models\User::class,
            'transformer' => \App\Transformers\EmployeeBatchEventTransformer::class,
            'handler' => \App\RabbitMQ\Handlers\EmployeeBatchHandler::class,
            'events' => [],
            'routing_key_prefix' => 'employee',
            'exchange' => 'default',
            'routing_key_patterns' => [
                'employee.batch_updated',
            ],
        ],

        'stories' => [
            'enabled' => true,
            'model' => \App\Models\Story::class,
            'transformer' => \App\Transformers\StoryEventTransformer::class,
            'relations' => ['thumbnailImage', 'images', 'product', 'shop'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'story',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'story.created',
                'story.updated',
                'story.deleted',
            ],
        ],

        'brands' => [
            'enabled' => true,
            'model' => \App\Models\Brand::class,
            'transformer' => \App\Transformers\BrandEventTransformer::class,
            'relations' => ['logoImage'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'brand',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'brand.created',
                'brand.updated',
                'brand.deleted',
            ],
        ],

        'faqs' => [
            'enabled' => true,
            'model' => \App\Models\Faq::class,
            'transformer' => \App\Transformers\FaqEventTransformer::class,
            'relations' => [],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'faq',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'faq.created',
                'faq.updated',
                'faq.deleted',
            ],
        ],

        'vacancies' => [
            'enabled' => true,
            'model' => \App\Models\Vacancy::class,
            'transformer' => \App\Transformers\VacancyEventTransformer::class,
            'relations' => [],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'vacancy',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'vacancy.created',
                'vacancy.updated',
                'vacancy.deleted',
            ],
        ],

        'pages' => [
            'enabled' => true,
            'model' => \App\Models\Page::class,
            'transformer' => \App\Transformers\PageEventTransformer::class,
            'relations' => [],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'page',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'page.created',
                'page.updated',
                'page.deleted',
            ],
        ],

        'merchant_sales' => [
            'enabled' => true,
            'model' => \App\Models\MerchantSale::class,
            'transformer' => \App\Transformers\MerchantSaleEventTransformer::class,
            'relations' => ['image', 'shop', 'products'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'merchant_sale',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'merchant_sale.created',
                'merchant_sale.updated',
                'merchant_sale.deleted',
            ],
        ],

        'product_group_filters' => [
            'enabled' => true,
            'model' => \App\Models\ProductGroupFilter::class,
            'transformer' => \App\Transformers\ProductGroupFilterEventTransformer::class,
            'relations' => ['values'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'product_group_filter',
            'delay_seconds' => 1,
        ],

        'merchant_product_groups' => [
            'enabled' => true,
            'model' => \App\Models\MerchantProductGroup::class,
            'transformer' => \App\Transformers\MerchantProductGroupEventTransformer::class,
            'relations' => ['image', 'shop', 'products', 'filterValues.filter'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'merchant_product_group',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'merchant_product_group.created',
                'merchant_product_group.updated',
                'merchant_product_group.deleted',
            ],
        ],

        'merchant_banners' => [
            'enabled' => true,
            'model' => \App\Models\MerchantBanner::class,
            'transformer' => \App\Transformers\MerchantBannerEventTransformer::class,
            'relations' => ['image', 'shop', 'product', 'category'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'merchant_banner',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'merchant_banner.created',
                'merchant_banner.updated',
                'merchant_banner.deleted',
            ],
        ],

        'merchant_reviews' => [
            'enabled' => true,
            'model' => \App\Models\MerchantReview::class,
            'transformer' => \App\Transformers\MerchantReviewEventTransformer::class,
            'relations' => ['shop'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'merchant_review',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'merchant_review.created',
                'merchant_review.updated',
                'merchant_review.deleted',
            ],
        ],

        'product_reviews' => [
            'enabled' => true,
            'model' => \App\Models\ProductReview::class,
            'transformer' => \App\Transformers\ProductReviewEventTransformer::class,
            'relations' => ['shop', 'product'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'product_review',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'product_review.created',
                'product_review.updated',
                'product_review.deleted',
            ],
        ],
        'order_reviews' => [
            // Только consume — отзывы создаются в storefront, наружу не публикуем
            'enabled' => false,
            'model' => \App\Models\OrderReview::class,
            'transformer' => \App\Transformers\OrderReviewEventTransformer::class,
            'relations' => ['order', 'client'],
            'routing_key_patterns' => [
                'order_review.created',
                'order_review.updated',
                'order_review.deleted',
            ],
        ],

        'merchant_promocodes' => [
            'enabled' => true,
            'model' => \App\Models\MerchantPromocode::class,
            'transformer' => \App\Transformers\MerchantPromocodeEventTransformer::class,
            'relations' => ['shop', 'products', 'categories'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'merchant_promocode',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'merchant_promocode.created',
                'merchant_promocode.updated',
                'merchant_promocode.deleted',
            ],
        ],

        'announcements' => [
            'enabled' => true,
            'model' => \App\Models\Announcement::class,
            'transformer' => \App\Transformers\AnnouncementEventTransformer::class,
            'relations' => ['image', 'promocode'],
            'events' => ['created', 'updated', 'deleted'],
            'routing_key_prefix' => 'announcement',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'announcement.created',
                'announcement.updated',
                'announcement.deleted',
            ],
        ],

        'announcement_notifications' => [
            'enabled' => true,
            'transformer' => \App\Transformers\AnnouncementNotificationEventTransformer::class,
            'listen_events' => [
                \App\Events\AnnouncementNotificationRequested::class,
            ],
            'event_type' => 'notification_sent',
            'model_property' => 'announcement',  // Модель announcement в свойстве announcement
            'routing_key_prefix' => 'announcement.notification',
            'exchange' => 'default',
            'delay_seconds' => 0,
        ],

        'integration_configs' => [
            'enabled' => true,
            'model' => \App\Models\IntegrationConfig::class,
            'transformer' => \App\Transformers\IntegrationConfigEventTransformer::class,
            'events' => ['created', 'updated'],
            'relations' => [],
            'condition' => \App\RabbitMQ\Conditions\PlatformWideIntegrationCondition::class,
            'routing_key_prefix' => 'integration_config',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'integration_config.created',
                'integration_config.updated',
            ],
        ],

        // admin_settings публикуются только на исход — потребители (storefront_back и др.)
        // используют их как feature flags (например, marketplace_mode) + конфиги вроде
        // marketplace_free_shipping_threshold. admin_back никогда не консумит свои же настройки.
        'admin_settings' => [
            'enabled' => true,
            'model' => \App\Models\AdminSetting::class,
            'transformer' => \App\Transformers\AdminSettingEventTransformer::class,
            'events' => ['created', 'updated', 'deleted'],
            'relations' => [],
            'routing_key_prefix' => 'admin_setting',
            'exchange' => 'default',
            'delay_seconds' => 1,
            'routing_key_patterns' => [
                'admin_setting.created',
                'admin_setting.updated',
                'admin_setting.deleted',
            ],
        ],

        'preorders' => [
            'enabled' => true,
            'model' => \App\Models\Preorder::class,
            'transformer' => \App\Transformers\PreorderEventTransformer::class,
            'relations' => ['client', 'product', 'shop'],
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
            'transformer' => \App\Transformers\ProductIssueReportEventTransformer::class,
            'relations' => ['client', 'product', 'shop'],
            'events' => ['created'],
            'routing_key_prefix' => 'product_issue_report',
            'routing_key_patterns' => [
                'product_issue_report.created',
            ],
        ],

        'onec_exchange_statuses' => [
            'enabled' => false, // только consume
            'model' => \App\Models\OneCExchangeStatus::class,
            'transformer' => \App\Transformers\OneCExchangeStatusEventTransformer::class,
            'routing_key_patterns' => [
                '1c_exchange.updated',
            ],
            'global_scope' => true,
        ],

        // Справочник 1С-категорий — admin_back ТОЛЬКО потребляет события от
        // 1c_parser, наружу не публикует (enabled=false). Маппинг событий в
        // локальную таблицу categories_1c делает Category1cEventTransformer.
        // LASTOCHKI-JAMI-10114, spec §4.2.1.
        'categories_1c' => [
            'enabled' => false, // только consume
            'model' => \App\Models\Category1c::class,
            'transformer' => \App\Transformers\Category1cEventTransformer::class,
            'routing_key_patterns' => [
                'category_1c.created',
                'category_1c.updated',
                'category_1c.deleted',
            ],
        ],

        // Publish-only аналитическое событие (LASTOCHKI-JAMI-11659):
        // отражение каждой записи order_history в шину. Eloquent-events
        // выключены — публикация только из App\Services\AnalyticsEvents
        // (хук в OrderHistory::log). Потребитель — lastochka_analytics_back.
        // Префикс order_stage (НЕ order.*) — чтобы не попадать в
        // inbound-очереди сервисов, подписанные на order.*.
        'order_stages' => [
            'enabled' => true,
            'model' => \App\Models\OrderHistory::class,
            'transformer' => \App\Transformers\AnalyticsStageTransformer::class,
            'events' => [],
            'routing_key_prefix' => 'order_stage',
            'exchange' => 'default',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Queues
    |--------------------------------------------------------------------------
    |
    | Configure queues for consuming events from other services.
    |
    */

    'inbound_queues' => [
        'categories' => env('RABBITMQ_QUEUE_CATEGORIES', 'admin_categories_in'),
        'categories_1c' => env('RABBITMQ_QUEUE_CATEGORIES_1C', 'admin_categories_1c_in'),
        'products' => env('RABBITMQ_QUEUE_PRODUCTS', 'admin_products_in'),
        'product_batches' => env('RABBITMQ_QUEUE_PRODUCT_BATCHES', 'admin_product_batches_in'),
        'orders' => env('RABBITMQ_QUEUE_ORDERS', 'admin_orders_in'),
        'customers' => env('RABBITMQ_QUEUE_CUSTOMERS', 'admin_customers_in'),
        'client_bonus_transactions' => env('RABBITMQ_QUEUE_BONUS_OPERATIONS', 'admin_bonus_operations_in'),
        'addresses' => env('RABBITMQ_QUEUE_CLIENT_ADDRESSES', 'admin_addresses_in'),
        'shops' => env('RABBITMQ_QUEUE_SHOPS', 'admin_shops_in'),
        'employees' => env('RABBITMQ_QUEUE_EMPLOYEES', 'admin_employees_in'),
        'employee_batches' => env('RABBITMQ_QUEUE_EMPLOYEE_BATCHES', 'admin_employee_batches_in'),
//       'users' => env('RABBITMQ_QUEUE_USERS', 'admin_users_in'),
        'merchant_reviews' => env('RABBITMQ_QUEUE_MERCHANT_REVIEWS', 'admin_merchant_reviews_in'),
        'product_reviews' => env('RABBITMQ_QUEUE_PRODUCT_REVIEWS', 'admin_product_reviews_in'),
        'preorders' => env('RABBITMQ_QUEUE_PREORDERS', 'admin_preorders_in'),
        'product_issue_reports' => env('RABBITMQ_QUEUE_PRODUCT_ISSUE_REPORTS', 'admin_product_issue_reports_in'),
        'order_reviews' => env('RABBITMQ_QUEUE_ORDER_REVIEWS', 'admin_order_reviews_in'),
        'onec_exchange_statuses' => env('RABBITMQ_QUEUE_1C_EXCHANGE_STATUS', 'admin_1c_exchange_status_in'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactional Outbox Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Transactional Outbox pattern worker settings.
    |
    */

    'outbox' => [
        'enabled' => env('RABBITMQ_OUTBOX_ENABLED', true),
        'poll_interval' => 1, // seconds between polling for unpublished events
        'batch_size' => 1000, // number of events to process per batch
        'memory_limit' => 128, // MB - worker will restart after reaching this
        'retention_days' => 7, // how long to keep published events
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure fallback queue for failed RabbitMQ publishes. This should
    | use a different queue driver (e.g., database) to avoid circular failures.
    |
    */

    'fallback_queue' => [
        'connection' => 'database', // Laravel queue connection (NOT rabbitmq)
        'queue' => 'rabbitmq_retry',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed event processing.
    |
    */

    'retry' => [
        'enabled' => true,
        'max_attempts' => 3,
        'backoff' => [60, 300, 900], // seconds to wait between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    |
    | Configure settings for the event consumer command.
    |
    */

    'consumer' => [
        'prefetch_count' => 1, // number of messages to prefetch from RabbitMQ
        'restart_after' => 1000, // restart consumer after N messages (memory leak prevention)
        'memory_limit' => 128, // MB - consumer will restart before reaching this
    ],

    /*
    |--------------------------------------------------------------------------
    | External Service URLs
    |--------------------------------------------------------------------------
    |
    | Map external service source names to their actual URLs for downloading images.
    | When consuming events with image URLs like http://localhost:8000/...,
    | localhost will be replaced with the correct service URL based on event source.
    |
    */

    'external_urls' => [
        'incesto' => env('INCESTO_URL', 'http://host.docker.internal:8000'),
        'store' => env('STORE_URL', 'http://host.docker.internal:8000'),
        'delivery' => env('DELIVERY_URL', 'http://gorbushka_delivery_back'),
        'purchase' => env('PURCHASE_URL', 'http://gorbushka_purchase_back'),
        'lk' => env('LK_URL', 'http://gorbushka_lk_back'),
        'landing' => env('LANDING_URL', 'http://gorbushka_landing_back'),
    ],

];
