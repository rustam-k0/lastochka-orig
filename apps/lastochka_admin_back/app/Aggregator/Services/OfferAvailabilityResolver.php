<?php

namespace App\Aggregator\Services;

use App\Models\PlatformProduct;
use App\Models\ProductOffer;
use App\Models\ShopZone;
use Illuminate\Support\Collection;

/**
 * Витрина: офферы платформенного товара, отфильтрованные по адресу доставки клиента
 * и отсортированные по цене (aggregator-режим, FOODLINK-10874, §6.6).
 *
 * ZoneFinder в проекте отсутствует — point-in-polygon реализован здесь поверх
 * shop_zones.coordinates (денормализованные lat/lng точки, упорядоченные по position).
 */
class OfferAvailabilityResolver
{
  /**
   * @param array{latitude?: float|null, longitude?: float|null}|null $point
   * @return Collection<int, ProductOffer>
   */
  public function resolveOffersForPoint(PlatformProduct $platform, ?array $point = null): Collection
  {
    $offers = $platform->offers()
      ->where('is_active', true)
      ->where('qty', '>', 0)
      ->with(['shop', 'localProduct']) // eager-load: shop_title / local_product_title без N+1
      ->get();

    $lat = $point['latitude'] ?? null;
    $lng = $point['longitude'] ?? null;

    if ($lat !== null && $lng !== null) {
      $coveringShopIds = $this->shopIdsCoveringPoint(
        $offers->pluck('shop_id')->unique()->all(),
        (float) $lat,
        (float) $lng
      );
      $offers = $offers->whereIn('shop_id', $coveringShopIds)->values();
    }

    return $offers->sortBy('price')->values();
  }

  /**
   * Из переданных shop_id оставить те, чья хотя бы одна зона покрывает точку.
   *
   * @param array<int, int> $shopIds
   * @return array<int, int>
   */
  private function shopIdsCoveringPoint(array $shopIds, float $lat, float $lng): array
  {
    if (empty($shopIds)) {
      return [];
    }

    $zones = ShopZone::with('coordinates')
      ->whereIn('shop_id', $shopIds)
      ->get();

    $covering = [];
    foreach ($zones as $zone) {
      if ($this->isPointInZone($zone, $lat, $lng)) {
        $covering[$zone->shop_id] = $zone->shop_id;
      }
    }

    return array_values($covering);
  }

  public function isPointInZone(ShopZone $zone, float $lat, float $lng): bool
  {
    $polygon = $zone->coordinates
      ->map(fn ($c) => ['lat' => (float) $c->latitude, 'lng' => (float) $c->longitude])
      ->all();

    return $this->pointInPolygon($lat, $lng, $polygon);
  }

  /**
   * Ray casting: точка внутри полигона. polygon — массив ['lat'=>,'lng'=>].
   *
   * @param array<int, array{lat: float, lng: float}> $polygon
   */
  private function pointInPolygon(float $lat, float $lng, array $polygon): bool
  {
    $count = count($polygon);
    if ($count < 3) {
      return false;
    }

    $inside = false;
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
      $yi = $polygon[$i]['lat'];
      $xi = $polygon[$i]['lng'];
      $yj = $polygon[$j]['lat'];
      $xj = $polygon[$j]['lng'];

      $intersect = (($yi > $lat) !== ($yj > $lat))
        && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);

      if ($intersect) {
        $inside = !$inside;
      }
    }

    return $inside;
  }
}
