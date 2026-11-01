<?php

namespace App\Services\Pricing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Type;
use App\Support\StockUnits;

/**
 * Лесенка «цены от количества» в purchase_back (MID-308): свежий снапшот позиции
 * при смене магазина заказа и чтение сохранённого снапшота.
 *
 * Флаг модуля гейтит только построение НОВОГО снапшота (freshSnapshot) и чтение
 * таблицы price_tiers; чтение сохранённого снапшота позиции (snapshotOf) от флага
 * не зависит: его наличие означает, что позиция уже посчитана по ступени.
 *
 * Один экземпляр на операцию: флаг модуля читается из admin_settings один раз.
 * Правило для вызывающего кода: null = «лесенки нет, считай прежней формулой».
 */
final class OrderItemTierPricing
{
  private ?bool $enabled = null;

  public function enabled(): bool
  {
    return $this->enabled ??= Order::isPriceTiersModuleEnabled();
  }

  /**
   * Снапшот лесенки позиции. null: снапшота нет либо он битый. От флага модуля
   * не зависит и к БД не обращается.
   */
  public function snapshotOf(OrderItem $item): ?PriceTierSnapshot
  {
    if ($item->price_tiers === null) {
      return null;
    }

    return PriceTierSnapshot::fromArray($item->price_tiers);
  }

  /**
   * Свежий снапшот из живой лесенки price_tiers магазина. Единственное место,
   * которое гейтится флагом модуля (при выключенном таблица price_tiers не
   * читается). Лесенка хранится в базовых единицах товара (value за базовую
   * единицу) и переводится в порции; base_price передаётся как есть: цена за
   * единицу позиции уже за порцию.
   *
   * @param  float  $basePrice  обычная цена за единицу в этом магазине
   *                            (OrderCreatorService::getPriceDuringOrder)
   */
  public function freshSnapshot(Product $product, ?int $shopId, float $basePrice, ?float $basePriceOld, bool $baseIsEligible): ?PriceTierSnapshot
  {
    if ($shopId === null || !$this->enabled()) {
      return null;
    }

    $rows = PriceTier::query()
      ->where('product_id', $product->id)
      ->where('shop_id', $shopId)
      ->orderBy('min_quantity')
      ->get(['min_quantity', 'value']);

    if ($rows->isEmpty()) {
      return null;
    }

    $ladder = PriceTierLadder::normalize(
      $rows->map(fn (PriceTier $tier) => [
        'min_quantity' => $tier->min_quantity,
        'value' => $tier->value,
      ])->all(),
      'value'
    );

    if ($ladder === null || $ladder === []) {
      return null;
    }

    return PriceTierSnapshot::build(
      $basePrice,
      $basePriceOld,
      $baseIsEligible,
      PriceTierLadder::toPortions($ladder, self::baseSize($product), self::stepSmallest($product))
    );
  }

  /**
   * Размер базовой единицы товара в наименьших единицах: кг, л = 1000; г, мл, шт = 1.
   * Базовая единица: products.base_unit_type_id (сырая единица 1С), у ручных
   * товаров info_weight_type_id. Тем же правилом admin_back считает prices.value.
   */
  public static function baseSize(Product $product): float
  {
    $baseUnit = (int) ($product->base_unit_type_id ?? $product->info_weight_type_id);

    return in_array($baseUnit, [Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_LITER], true)
      ? 1000.0
      : 1.0;
  }

  /**
   * Размер порции в наименьших единицах — той порции, за которую назначена цена
   * позиции (prices.value). Совпадает с delivery_back: квант (info_weight в
   * единицах info_weight_type_id) либо, без кванта, базовая единица.
   *
   * Раньше для unit_type_id = UNIT_GRAM здесь всегда было 1000 (цена бралась из
   * price_per_kg) в расчёте, что весовых с квантом нет. На проде Ласточки их 982
   * (порция 300 г, unit_type_id = UNIT_GRAM): лесенка уходила в 1000 раз мимо.
   */
  public static function stepSmallest(Product $product): float
  {
    if ((int) ($product->info_weight ?: 0) > 0) {
      return (float) StockUnits::portionInSmallest($product);
    }

    return self::baseSize($product);
  }

  /**
   * Снапшот-пустышка «снапшот снят» (раздел 3 обзора): у позиции БЫЛ снапшот, а
   * у нового основания цены лесенки нет. NULL в колонку писать нельзя: ключ
   * price_tiers в исходящем событии получает только позиция с непустой колонкой,
   * и витрина не узнала бы, что свой снапшот пора снять. На пустой лесенке
   * PriceTierSnapshot::fromArray() возвращает null, поэтому все расчёты идут как
   * у позиции без снапшота.
   *
   * @return array{base_price: float, base_price_old: null, base_is_eligible: true, tiers: array<int, mixed>}
   */
  public static function stubSnapshot(float $basePrice): array
  {
    return [
      'base_price' => round($basePrice, 2),
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ];
  }

  /**
   * Снапшот позиции для выдачи наружу (GraphQL OrderItem.price_tiers):
   * нормализованный снапшот; пустышка «снапшот снят» как есть (tiers: [], админка
   * показывает пометку только при непустом tiers); null у позиций без снапшота
   * и при битых данных.
   *
   * @return array{base_price: float, base_price_old: float|null, base_is_eligible: bool, tiers: array<int, mixed>}|null
   */
  public static function exposedSnapshot(OrderItem $item): ?array
  {
    $raw = $item->price_tiers;

    if (!is_array($raw) || !array_key_exists('tiers', $raw)) {
      return null;
    }

    $snapshot = PriceTierSnapshot::fromArray($raw);

    if ($snapshot !== null) {
      return $snapshot->toArray();
    }

    if ($raw['tiers'] !== []) {
      return null;
    }

    return [
      'base_price' => round((float) ($raw['base_price'] ?? 0), 2),
      'base_price_old' => isset($raw['base_price_old']) ? round((float) $raw['base_price_old'], 2) : null,
      'base_is_eligible' => (bool) ($raw['base_is_eligible'] ?? true),
      'tiers' => [],
    ];
  }
}
