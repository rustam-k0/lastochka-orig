<?php

namespace App\GraphQL\Queries;

use App\Models\Order;
use App\Models\OrderHistory;
use Illuminate\Support\Collection;

final class OrderHistoryQuery
{
    /**
     * @return Collection<int, OrderHistory>
     */
    public function __invoke($_, array $args): Collection
    {
        $orderId = (int) $args['order_id'];
        $order = Order::find($orderId);
        $orderIds = $order ? $order->selfAndChildOrderIds() : [$orderId];

        return OrderHistory::whereIn('order_id', $orderIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
