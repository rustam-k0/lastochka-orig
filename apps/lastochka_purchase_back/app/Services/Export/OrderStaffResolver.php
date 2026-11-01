<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Assembly;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Staff;
use Illuminate\Support\Collection;

/**
 * Батчевый резолв сборщика и курьеров сразу для пачки заказов.
 *
 * Поштучные OrderCollectorResolver / OrderCouriersResolver дают N+1 и на
 * выгрузке в 10 000 заказов неприемлемы. Логика выбора зеркалит их:
 * сборщик — пользователь сборки с максимальным id среди заказа и его
 * подзаказов, курьеры — пользователи всех назначений на те же заказы.
 */
class OrderStaffResolver
{
    /**
     * @param Collection<int, Order> $orders
     * @return array<int, array{collector: ?Staff, couriers: array<int, Staff>}>
     */
    public function resolve(Collection $orders): array
    {
        $parentIds = $orders->pluck('id')->all();

        if (!$parentIds) {
            return [];
        }

        // childId => parentId; сам родитель тоже отображается сам в себя
        $ownerByOrderId = array_combine($parentIds, $parentIds);

        Order::query()
            ->whereIn('parent_order_id', $parentIds)
            ->get(['id', 'parent_order_id'])
            ->each(function (Order $child) use (&$ownerByOrderId) {
                $ownerByOrderId[$child->id] = (int) $child->parent_order_id;
            });

        $allIds = array_keys($ownerByOrderId);

        $result = [];
        foreach ($parentIds as $id) {
            $result[$id] = ['collector' => null, 'couriers' => []];
        }

        $latestAssemblyId = [];

        Assembly::query()
            ->whereIn('order_id', $allIds)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->each(function (Assembly $assembly) use (&$result, &$latestAssemblyId, $ownerByOrderId) {
                $owner = $ownerByOrderId[$assembly->order_id] ?? null;

                if ($owner === null) {
                    return;
                }

                // Сортировка по возрастанию id — последняя запись побеждает,
                // даже если у неё нет доступного пользователя (мягко удалён) —
                // без отката на предыдущую сборку. Так же ведёт себя поштучный
                // OrderCollectorResolver::latest('id')->first()?->user.
                if (($latestAssemblyId[$owner] ?? 0) <= $assembly->id) {
                    $latestAssemblyId[$owner] = $assembly->id;
                    $result[$owner]['collector'] = $assembly->user;
                }
            });

        DeliveryOrder::query()
            ->whereIn('order_id', $allIds)
            ->with('delivery.user')
            ->get()
            ->each(function (DeliveryOrder $deliveryOrder) use (&$result, $ownerByOrderId) {
                $owner = $ownerByOrderId[$deliveryOrder->order_id] ?? null;
                $user = $deliveryOrder->delivery?->user;

                if ($owner === null || !$user) {
                    return;
                }

                $result[$owner]['couriers'][$user->id] = $user;
            });

        foreach ($result as $orderId => $staff) {
            $result[$orderId]['couriers'] = array_values($staff['couriers']);
        }

        return $result;
    }
}
