<?php

namespace App\GraphQL\Queries;

use App\Models\Order;
use App\Models\ShopZone;
use App\Services\ShopZoneMatcher;

/**
 * Резолвер поля Order.delivery_zone — зона доставки, вычисленная по
 * координатам адреса заказа (point-in-polygon), т.к. зона нигде не
 * сохраняется на самом заказе (см. docs/superpowers/specs/2026-07-04-order-delivery-info-block-design.md).
 */
final class OrderDeliveryZoneResolver
{
    public function __invoke(Order $order): ?ShopZone
    {
        $clientInfo = $order->clientInfo;

        if (!$order->shop_id || !$clientInfo || !$clientInfo->latitude || !$clientInfo->longitude) {
            return null;
        }

        return (new ShopZoneMatcher())->findMatchingZone(
            $order->shop_id,
            (float) $clientInfo->latitude,
            (float) $clientInfo->longitude
        );
    }
}
