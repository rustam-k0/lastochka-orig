<?php

namespace App\GraphQL\Mutations\ShopZone;

use App\Models\Shop;
use App\Models\ShopZone;
use App\Services\ModuleConfigService;
use App\Services\Pricing\DeliveryPriceLadder;

final class ShopZoneCreteOrUpdate
{
  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {

    $coordinates = null;

    //Загрузка координат
    if (isset($args['geojson'])) {
      $coordinates = $this->getCoordinates($args['geojson']);

      if (!$coordinates) {
        return [
          'code' => 500,
          'message' => 'Ошибка чтения GeoJson'
        ];
      }
    }


    \DB::transaction(function () use ($args, $coordinates) {
      // Обновляем информацию о доставке
      $zone = $this->createOrUpdateZoneInfo($args);

      // Обновляем информацию о зоне доставке
      $this->saveZoneCoordinates($coordinates, $zone);

      // Зона входит в payload магазина (delivery_price/min_order_price/free_delivery_from),
      // touch публикует shop.updated — storefront пересчитает условия доставки и сбросит кеш
      Shop::find($zone->shop_id)?->touch();
    });


    return [
      'code' => 200,
      'message' => 'OK'
    ];
  }

  private function getCoordinates($geojson)
  {
    $file = file_get_contents($geojson->path());

    $json = json_decode($file, true);

    if (!$json || !isset($json['features']) || !is_array($json['features'])) {
      return false;
    }

    // Ищем геометрию зоны среди ВСЕХ features, а не только первого:
    // Яндекс Конструктор карт кладёт в ту же FeatureCollection маркеры-точки
    // (Point) и саму зону может экспортировать как LineString (нарисованный
    // контур), а не Polygon. Берём первый Polygon/LineString, точки игнорируем.
    foreach ($json['features'] as $feature) {
      $type = $feature['geometry']['type'] ?? null;

      if ($type === 'Polygon') {
        return $feature['geometry']['coordinates'][0] ?? false;
      }

      if ($type === 'LineString') {
        return $feature['geometry']['coordinates'] ?? false;
      }
    }

    return false;
  }

  private function saveZoneCoordinates(mixed $coordinates, ShopZone $zone): void
  {
    //Если координаты не загружены, оставляем ту же зону
    if (!$coordinates) {
      return;
    }

    $zone->coordinates()->delete();

    foreach ($coordinates as $key => $coordinate) {
      $zone->coordinates()->create([
        'latitude' => $coordinate[1],
        'longitude' => $coordinate[0],
        'position' => $key + 1,
      ]);
    }
  }

  private function createOrUpdateZoneInfo(array $args): ShopZone
  {
    $zone = isset($args['id'])
      ? ShopZone::find($args['id'])
      : new ShopZone();

    $zone->shop_id = $args['shop_id'];
    $zone->name = $args['name'] ?? null;
    $zone->delivery_price = $args['delivery_price'];
    $zone->min_order_price = $args['min_order_price'];

    // Модуль «Диапазоны стоимости доставки» выключен (MID-280): ключ
    // delivery_price_ranges не применяется вовсе — ступени, сохранённые до
    // выключения, остаются в колонке как есть, free_delivery_from ведёт себя
    // как до фичи (аргумент мутации применяется напрямую).
    if (ModuleConfigService::isDeliveryPriceRangesEnabled()) {
      $this->applyDeliveryPriceRanges($zone, $args);
    } else {
      $zone->free_delivery_from = $args['free_delivery_from'] ?? null;
    }

    $zone->delivery_type = $args['delivery_type'] ?? 'own';
    $zone->delivery_days = $this->normalizeDeliveryDays($args['delivery_days'] ?? null);
    $zone->delivery_lead_minutes = $this->normalizeDeliveryLeadMinutes($args['delivery_lead_minutes'] ?? null);

    // Опциональные поля: обновляем ТОЛЬКО если ключ реально передан,
    // иначе апдейт без этих аргументов затёр бы сохранённые значения.
    if (array_key_exists('assembly_price', $args)) {
      $zone->assembly_price = $args['assembly_price'] === null ? null : (int) $args['assembly_price'];
    }

    // Явный null (частый кейс — GraphQL-клиент шлёт все переменные с null)
    // НЕ должен деактивировать зону: (bool) null === false молча затёр бы is_active.
    // Меняем только при реально переданном не-null значении.
    if (array_key_exists('is_active', $args) && $args['is_active'] !== null) {
      $zone->is_active = (bool) $args['is_active'];
    }

    $zone->save();

    return $zone;
  }

  /**
   * Ступени стоимости доставки и производный порог бесплатной доставки (MID-280).
   *
   * Передан массив delivery_price_ranges (в том числе []): ступени — источник
   * правды, free_delivery_from вычисляется из них (ступень с ценой 0), аргумент
   * free_delivery_from игнорируется. Не передан или null (админка до выкладки
   * MID-280; Apollo шлёт null для незаполненной переменной): порог из аргумента
   * free_delivery_from, как раньше; платные ступени ниже нового порога
   * сохраняются, бесплатная ступень заменяется новым порогом.
   *
   * Вызывается после записи delivery_price: цена зоны нужна лестнице.
   */
  private function applyDeliveryPriceRanges(ShopZone $zone, array $args): void
  {
    if (isset($args['delivery_price_ranges']) && is_array($args['delivery_price_ranges'])) {
      $ranges = DeliveryPriceLadder::normalize($args['delivery_price_ranges']) ?? [];

      $zone->delivery_price_ranges = $ranges;
      $zone->free_delivery_from = DeliveryPriceLadder::fromRanges((int) $zone->delivery_price, $ranges)->freeFrom();

      return;
    }

    $freeDeliveryFrom = isset($args['free_delivery_from']) ? (int) $args['free_delivery_from'] : null;

    $ranges = array_values(array_filter(
      DeliveryPriceLadder::normalize($zone->delivery_price_ranges) ?? [],
      function (array $range) use ($freeDeliveryFrom): bool {
        return $range['price'] > 0
          && ($freeDeliveryFrom === null || $range['from_amount'] < $freeDeliveryFrom);
      }
    ));

    if ($freeDeliveryFrom !== null) {
      $ranges[] = ['from_amount' => $freeDeliveryFrom, 'price' => 0];
    }

    $zone->delivery_price_ranges = $ranges;
    $zone->free_delivery_from = $freeDeliveryFrom;
  }

  /**
   * Нормализует минимальное время до доставки (lead time) в минутах.
   * Не передано / не число / <= 0 → null (каноническое «без задержки»).
   *
   * @param mixed $minutes
   * @return int|null
   */
  private function normalizeDeliveryLeadMinutes($minutes): ?int
  {
    if (!is_numeric($minutes)) {
      return null;
    }

    $minutes = (int) $minutes;

    return $minutes > 0 ? $minutes : null;
  }

  /**
   * Нормализует дни недели: unique + sorted, только 1-7.
   * Пустой/невалидный набор → null (канонические «все дни»).
   *
   * @param mixed $days
   * @return array<int>|null
   */
  private function normalizeDeliveryDays($days): ?array
  {
    if (!is_array($days)) {
      return null;
    }

    $days = array_map('intval', $days);
    $days = array_filter($days, fn (int $day) => $day >= 1 && $day <= 7);
    $days = array_values(array_unique($days));
    sort($days);

    return $days === [] ? null : $days;
  }
}
