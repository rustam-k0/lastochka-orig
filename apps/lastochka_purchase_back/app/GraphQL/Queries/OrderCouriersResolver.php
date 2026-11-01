<?php

namespace App\GraphQL\Queries;

use App\Models\DeliveryOrder;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Курьеры заказа = уникальные пользователи доставок среди самого заказа и его
 * подзаказов (совместная доставка / расщепление).
 *
 * В списке deliveryOrders/childOrders приходят eager-load'ом (scopeAdmin) —
 * считаем из памяти без N+1. Для одиночного заказа — фолбэк на запрос.
 */
final class OrderCouriersResolver
{
    public function __invoke(Order $order): Collection
    {
        if ($order->relationLoaded('deliveryOrders') && $order->relationLoaded('childOrders')) {
            return $order->deliveryOrders
                ->concat($order->childOrders->flatMap->deliveryOrders)
                ->pluck('delivery.user')
                ->filter()
                ->unique('id')
                ->values();
        }

        return DeliveryOrder::whereIn('order_id', $order->selfAndChildOrderIds())
            ->with('delivery.user')
            ->get()
            ->pluck('delivery.user')
            ->filter()
            ->unique('id')
            ->values();
    }
}
