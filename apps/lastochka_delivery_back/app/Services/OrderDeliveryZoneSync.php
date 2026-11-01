<?php

namespace App\Services;

use App\Models\ClientOrderInfo;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Персист зоны доставки на заказе (orders.zone_id) для аналитики.
 * Зона считается по координатам client_infos + shop_id (point-in-polygon)
 * и каскадится на подзаказы (они делят адрес родителя). Запись через
 * DB::table — без Eloquent-событий, чтобы не порождать outbox-шторм.
 */
class OrderDeliveryZoneSync
{
    public function __construct(private readonly ShopZoneMatcher $matcher = new ShopZoneMatcher())
    {
    }

    public function sync(Order $order): void
    {
        if ($order->parent_order_id !== null) {
            return; // зона подзаказа наследуется от родителя
        }

        $clientInfo = ClientOrderInfo::query()->where('order_id', $order->id)->first();

        $zoneId = null;
        if ($order->shop_id && $clientInfo && $clientInfo->latitude && $clientInfo->longitude) {
            $zoneId = $this->matcher->findMatchingZone(
                (int) $order->shop_id,
                (float) $clientInfo->latitude,
                (float) $clientInfo->longitude
            )?->id;
        }

        DB::table('orders')->where('id', $order->id)->update(['zone_id' => $zoneId]);
        DB::table('orders')->where('parent_order_id', $order->id)->update(['zone_id' => $zoneId]);

        $order->zone_id = $zoneId;
    }
}
