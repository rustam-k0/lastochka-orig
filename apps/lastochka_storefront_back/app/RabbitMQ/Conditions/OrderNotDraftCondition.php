<?php

declare(strict_types=1);

namespace App\RabbitMQ\Conditions;

use App\Enums\OrderStatus;
use Comrades\RabbitMQEvents\Contracts\PublishCondition;
use Illuminate\Database\Eloquent\Model;

/**
 * Не публиковать черновики заказов (модуль draft_orders_until_payment).
 *
 * Черновик — оформленный, но не оплаченный заказ: для курьерки, админки и 1С
 * его не существует. Событие order.created за него выпускает
 * FinalizeDraftOrderAction — после подтверждения холда, когда статус уже pending.
 */
final class OrderNotDraftCondition implements PublishCondition
{
    public function __invoke(Model $order): bool
    {
        return $order->status !== OrderStatus::Draft;
    }
}
