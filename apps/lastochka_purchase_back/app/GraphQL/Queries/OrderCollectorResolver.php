<?php

namespace App\GraphQL\Queries;

use App\Models\Assembly;
use App\Models\Order;
use App\Models\Staff;

/**
 * Сборщик заказа = пользователь последней (по id) сборки среди самого заказа
 * и его подзаказов (см. Order::selfAndChildOrderIds — расщепление по продавцам).
 *
 * В списке (admin_orders_paginate) assemblies/childOrders приходят eager-load'ом
 * (scopeAdmin) — считаем из памяти без N+1. Для одиночного заказа
 * (admin_orders_item, без eager-load) — фолбэк на запрос.
 */
final class OrderCollectorResolver
{
    public function __invoke(Order $order): ?Staff
    {
        if ($order->relationLoaded('assemblies') && $order->relationLoaded('childOrders')) {
            return $order->assemblies
                ->concat($order->childOrders->flatMap->assemblies)
                ->sortByDesc('id')
                ->first()?->user;
        }

        $assembly = Assembly::whereIn('order_id', $order->selfAndChildOrderIds())
            ->with('user')
            ->latest('id')
            ->first();

        return $assembly?->user;
    }
}
