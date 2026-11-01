<?php

namespace App\RabbitMQ\Conditions;

use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Дочерние (под-)заказы не публикуют события в RabbitMQ —
 * внешние системы (CRM/1С/storefront) не знают о расщеплении на зоны.
 */
class OrderNotSubOrderCondition implements PublishCondition
{
    public function __invoke(Model $model): bool
    {
        return $model->parent_order_id === null;
    }
}
