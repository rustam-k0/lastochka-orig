<?php

namespace App\Services;

use App\Models\ShopZone;

/**
 * Point-in-polygon сопоставление точки (координаты адреса заказа) с зоной
 * доставки магазина (ShopZone.coordinates). Копия одноимённого сервиса
 * purchase_back — admin_back персистит зону в orders.zone_id для аналитики.
 */
class ShopZoneMatcher
{
    public function findMatchingZone(int $shopId, float $lat, float $lng): ?ShopZone
    {
        $zones = ShopZone::with('coordinates')
            ->where('shop_id', $shopId)
            ->orderBy('id')
            ->get();

        foreach ($zones as $zone) {
            if ($this->isPointInZone($zone, $lat, $lng)) {
                return $zone;
            }
        }

        return null;
    }

    public function isPointInZone(ShopZone $zone, float $lat, float $lng): bool
    {
        $polygon = $zone->coordinates
            ->map(fn ($c) => ['lat' => (float) $c->latitude, 'lng' => (float) $c->longitude])
            ->all();

        return $this->pointInPolygon($lat, $lng, $polygon);
    }

    // Ray casting: точка внутри полигона. polygon — массив ['lat'=>,'lng'=>].
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $yi = $polygon[$i]['lat']; $xi = $polygon[$i]['lng'];
            $yj = $polygon[$j]['lat']; $xj = $polygon[$j]['lng'];
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
