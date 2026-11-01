<?php

namespace App\GraphQL\Queries;

use App\Models\AssemblyPackage;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

final class OrderPackagesResolver
{
    /**
     * Резолвер поля Order.packages для курьерского API.
     *
     * Для parent-заказа (split по зонам сборки) аккумулирует packages со всех
     * дочерних подзаказов плюс собственных. Для child-заказа возвращает только
     * свои. Сборщик сохраняет packages на конкретной сборке (assembly), которая
     * привязана к child-заказу, поэтому plain `@hasMany` по parent.id всегда
     * возвращал бы пустой список — курьер не видел плашку с количеством пакетов
     * (LASTOCHKI-JAMI-10082).
     */
    public function __invoke(Order $order): Collection
    {
        if ($order->parent_order_id !== null) {
            return $order->packages()->orderBy('id')->get();
        }

        $childIds = Order::where('parent_order_id', $order->id)->pluck('id')->all();
        $orderIds = array_merge([$order->id], $childIds);

        return AssemblyPackage::whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get();
    }
}
