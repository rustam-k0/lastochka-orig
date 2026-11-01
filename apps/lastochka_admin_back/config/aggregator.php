<?php

/**
 * Конфигурация aggregator-режима (FOODLINK-10874).
 *
 * Включение режима — через admin_settings.project_modules.aggregator_enabled
 * (см. App\Aggregator\Support\AggregatorMode), НЕ через этот файл. Здесь — тюнинг.
 */
return [

    /*
    | RabbitMQ routing-key префиксы исходящих aggregator-событий.
    | Bagisto-консьюмер подписан на aggregator.offer.* и aggregator.platform_product.*.
    */
    'routing' => [
        'offer_prefix' => 'aggregator.offer',
        'platform_product_prefix' => 'aggregator.platform_product',
    ],

    /*
    | Статус скрытого товара в карантине: admin_back products.status_id.
    | Товар в карантине не публикуется на витрине (docs/aggregator-mode.md §6.3).
    | 3 = CODE_NOT_PUBLISHED (Status::CODE_NOT_PUBLISHED).
    */
    'quarantine_hidden_status_id' => 3,

];
