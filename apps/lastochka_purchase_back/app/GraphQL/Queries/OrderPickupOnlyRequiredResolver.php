<?php

namespace App\GraphQL\Queries;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * Резолвер поля Order.pickup_only_required — true, если хотя бы одна позиция
 * заказа (включая дочерние подзаказы по зонам сборки, см. OrderItem::scopeForOrder)
 * ссылается на товар с products.pickup_only=true.
 */
final class OrderPickupOnlyRequiredResolver
{
    public function __invoke(Order $order): bool
    {
        return OrderItem::query()
            ->forOrder(['order_id' => $order->id])
            ->whereHas('product', fn ($q) => $q->where('pickup_only', true))
            ->exists();
    }
}
