<?php

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Дочерние (под-)заказы по зонам сборки (marketplace) не публикуют события в RabbitMQ —
 * внешние системы (CRM/1С/storefront) не знают о расщеплении на зоны.
 *
 * Исключение (aggregator-режим, FOODLINK-10874): дочерние со split_strategy='shop'
 * публикуются — каждый уходит в 1С своего магазина-поставщика по его shop_code (§6.8).
 * Для standard/marketplace поведение не меняется: там split_strategy='shop' не бывает
 * (его ставит только OrderAggregatorSplitter при включённом флаге) → fail-safe.
 */
class OrderNotSubOrderCondition implements PublishCondition
{
    public function __invoke(Model $model): bool
    {
        return $model->parent_order_id === null
            || $model->split_strategy === 'shop';
    }
}
