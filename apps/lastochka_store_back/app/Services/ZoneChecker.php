<?php

namespace App\Services;

use App\Models\ShopZone;


class ZoneChecker
{

  /**
   * Проверяет, находится ли клиент в зоне доставки для магазина.
   *
   * @param float $latitude Широта клиента.
   * @param float $longitude Долгота клиента.
   * @return bool Возвращает true, если клиент находится в зоне доставки хотя бы одного магазина, иначе - false.
   */
  public static function isInZone(float $latitude, float $longitude): bool
  {

    // Если установлена интеграция с geos, проверяем через него
    if (\geoPHP::geosInstalled()) {
      return self::checkZoneWithGeoPhp($latitude, $longitude);
    }

    return self::checkZone($latitude, $longitude);
  }

  private static function checkZone(float $latitude, float $longitude): bool
  {
    $shopZones = self::getShopZones();
    foreach ($shopZones as $zone) {

      $polygon = self::createPolygon($zone->coordinates);

      // Проверяем, находится ли клиент в текущей зоне
      if (self::pointInPolygon($latitude, $longitude, $polygon)) {
        return true;
      }
    }
    return false;
  }
  public static function getAvailableZones(float $latitude, float $longitude): array
  {
    $shopZones = self::getShopZones();
    $availableZonesId = [];
    foreach ($shopZones as $zone) {

      $polygon = self::createPolygon($zone->coordinates);

      // Проверяем, находится ли клиент в текущей зоне
      if (self::pointInPolygon($latitude, $longitude, $polygon)) {
        $availableZonesId[] = $zone->id;
      }
    }

    return $availableZonesId;
  }

  /**
   * Проверяет, находится ли заданная точка внутри многоугольника
   * @param float $latitude Широта точки
   * @param float $longitude Долгота точки
   * @param array $polygon Массив координат многоугольника, каждая точка задается ассоциативным массивом вида [0 => широта, 1 => долгота]
   * @return bool true, если точка находится внутри многоугольника, false - в противном случае
   */
  protected static function pointInPolygon(float $latitude, float $longitude, array $polygon): bool
  {
    // Вычисляем количество вершин многоугольника
    $numPoints = count($polygon);

    // Инициализируем переменные
    $i = $j = $c = 0;

    // Проверяем каждый отрезок многоугольника
    for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {
      // Проверяет, находится ли точка между двумя точками многоугольника по оси долготы
      //Если точка находится между двумя точками многоугольника по оси долготы,
      // то следующее условие проверяет, находится ли она выше или ниже линии, соединяющей две точки многоугольника.
      // Это делается с помощью уравнения прямой, проходящей через две точки многоугольника и проверки, лежит ли точка выше или ниже этой прямой.
      // Если она находится выше, то переменная $c увеличивается на 1. Если она находится ниже или на линии, то переменная $c не меняется.
      if (((($polygon[$i][1] <= $longitude) && ($longitude < $polygon[$j][1])) ||
          (($polygon[$j][1] <= $longitude) && ($longitude < $polygon[$i][1]))) &&
        ($latitude < ($polygon[$j][0] - $polygon[$i][0]) * ($longitude - $polygon[$i][1]) / ($polygon[$j][1] - $polygon[$i][1]) + $polygon[$i][0])) {
        $c = !$c;
      }
    }
    return $c;
  }


  private static function checkZoneWithGeoPhp(float $latitude, float $longitude): bool
  {
    $shop_zones = self::getShopZones();
    foreach ($shop_zones as $zone) {

      $polygon = json_encode([
        'type' => 'Polygon',
        'coordinates' => [self::createPolygon($zone->coordinates)]
      ]);

      $point = json_encode([
        'type' => 'Point',
        'coordinates' => [$latitude, $longitude]
      ]);

      $geo_client = \geoPHP::load($point, 'json');
      $geo_zone = \geoPHP::load($polygon, 'wkt');
      if ($geo_zone->contains($geo_client)) {
        return true;
      }
    }
    return false;
  }

  public static function getNearestZone($latitude, $longitude, $shop_id): ?ShopZone
  {
    // First, get all the shop zones
    $shopZones = self::getShopZones($shop_id);

    $nearestZone = null;

    // Loop through each shop zone
    foreach ($shopZones as $shopZone) {

      // Create an array of coordinates
      $polygon = self::createPolygon($shopZone->coordinates);

      // Use the PHP function pointInPolygon to check if the latitude and longitude are within the polygon
      if (self::pointInPolygon($latitude, $longitude, $polygon)) {
        // If the point is in the zone, check if it is the smallest one
        if (!$nearestZone || $shopZone->delivery_price < $nearestZone->delivery_price) {
          $nearestZone = $shopZone;
        }
      }
    }

    // Return the smallest zone
    return $nearestZone;
  }

  protected static function getShopZones(?int $shop_id = null)
  {
    $query = ShopZone::with('coordinates', 'shop');

    if ($shop_id) {
      $query->whereShopId($shop_id);
    }

    return $query->get();
  }

  private static function createPolygon(mixed $coordinates): array
  {
    $polygon = [];
    foreach ($coordinates as $coordinate) {
      // Заполняем массив координат многоугольника зоны
      $polygon[] = [$coordinate->latitude, $coordinate->longitude];
    }

    return $polygon;
  }
}
