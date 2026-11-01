<?php

declare(strict_types=1);

namespace App\GraphQL\Queries\ConfigurableProduct;

use App\Models\Status;
use Illuminate\Support\Facades\DB;

final class VariantOrdersCount
{
    private const ACTIVE_ORDER_STATUS_IDS = [
        Status::ORDER_CREATED,
        Status::ORDER_PREPARING,
        Status::ORDER_ASSEMBLED,
        Status::ORDER_IN_DELIVERY,
    ];

    public function __invoke($_, array $args): array
    {
        $variantId = (int) $args['variant_id'];

        $activeOrders = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_id', $variantId)
            ->whereIn('orders.status_id', self::ACTIVE_ORDER_STATUS_IDS)
            ->distinct()
            ->count('orders.id');

        $totalOrders = DB::table('order_items')
            ->where('order_items.product_id', $variantId)
            ->distinct()
            ->count('order_items.order_id');

        return [
            'variant_id' => $variantId,
            'active_orders' => $activeOrders,
            'total_orders' => $totalOrders,
        ];
    }
}
