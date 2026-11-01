<?php

namespace App\Services\Pricing;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\Type;
use App\Support\DeliveryReturn;

/**
 * Цена позиции заказа по лесенке «цены от количества» (MID-308): единая точка для
 * сборки, добавления товара, замены, возврата курьеру и событий заказа.
 *
 * Что зависит от флага модуля. Пересчёт по УЖЕ ИМЕЮЩЕМУСЯ у позиции снапшоту
 * (snapshotOf, quoteFor, deliveredQuote) от флага не зависит: снапшот приходит с
 * витрины, её флаг живёт в другой БД и может разойтись с флагом курьерки, а цена
 * позиции уже посчитана по ступени. Флагом гейтится только построение НОВОГО
 * снапшота из живой лесенки (freshSnapshot): добавка сборщика, товар-замена.
 *
 * Один экземпляр на операцию: флаг читается из admin_settings один раз. Статического
 * мемо нет намеренно: сервис зовут и долгоживущие процессы (outbox-воркер,
 * консьюмер), и тесты, переключающие флаг внутри одного процесса.
 *
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
   * Котировка позиции для заданного числа порций по её снапшоту.
   */
  public function quoteFor(OrderItem $item, float $portions): ?PriceTierQuote
  {
    $snapshot = $this->snapshotOf($item);

    return $snapshot !== null ? $snapshot->quote($portions) : null;
  }

  /**
   * Итог строки для массовых подтверждений сборки (AssemblyStartCooking,
   * REST /backend-delivery/order/assembly): позиция со снапшотом лесенки
   * считается по ступени для заданного количества независимо от флага модуля
   * (снапшот уже посчитан), позиция без снапшота — прежней формулой
   * price_during_order × quantity без округления, как до фичи MID-308.
   */
  public function linePriceFor(OrderItem $item, float $quantity): float
  {
    $quote = $this->quoteFor($item, $quantity);

    return $quote !== null
      ? round($quote->unitPrice * $quantity, 2)
      : $item->price_during_order * $quantity;
  }

  /**
   * Котировка штучной позиции для ДОСТАВЛЕННОГО количества после возврата курьеру.
   *
   * null: возврата нет, позиция весовая (возвращается только целиком, цена за
   * порцию не меняется), доставлено 0 либо у позиции нет снапшота.
   */
  public function deliveredQuote(OrderItem $item, ?int $actualWeight, int $collected, int $returned): ?PriceTierQuote
  {
    $delivered = $collected - $returned;

    if ($returned <= 0 || $actualWeight !== null || $delivered <= 0) {
      return null;
    }

    return $this->quoteFor($item, (float) $delivered);
  }

  /**
   * Свежий снапшот из живой лесенки price_tiers магазина (новая позиция сборщика,
   * товар-замена). Единственное место, которое гейтится флагом модуля. Лесенка
   * хранится в базовых единицах товара (value за базовую единицу) и переводится
   * в порции; base_price берётся из prices.value как есть: у квантового товара
   * это уже цена кванта, то есть порции.
   *
   * @param  float  $basePrice  обычная цена порции в магазине заказа (prices.value)
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
   * Базовая единица: сырая единица 1С (products.base_unit_type_id); у ручных товаров
   * её нет, тогда единица карточки (info_weight_type_id). Тем же правилом admin_back
   * (CategoryQuantumService::applyToProduct) считает prices.value.
   */
  public static function baseSize(Product $product): float
  {
    $baseUnit = (int) ($product->base_unit_type_id ?? $product->info_weight_type_id);

    return in_array($baseUnit, [Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_LITER], true)
      ? 1000.0
      : 1.0;
  }

  /**
   * Размер порции в наименьших единицах, той самой порции, за которую назначены
   * prices.value и unit_price витрины: квант задан (info_weight > 0) → шаг
   * ProductConfirm::getStepGrams(); кванта нет → товар продаётся базовой единицей.
   *
   * getStepGrams() без кванта здесь не годится: для товаров 1С без единицы
   * (info_weight_type_id NULL) он отдаёт 1000, а продаются они штуками.
   */
  public static function stepSmallest(Product $product): float
  {
    if ((int) ($product->info_weight ?: 0) > 0) {
      return (float) ProductConfirm::getStepGrams($product);
    }

    return self::baseSize($product);
  }

  /**
   * Порции весовой позиции по фактическому весу: max(1, round(вес / шаг)), та же
   * формула, что пишет quantity в AssemblyFinish и считает возвраты
   * (DeliveryReturn::collectedPortions). Недовес в пределах половины шага порцию,
   * а значит и ступень, не меняет.
   */
  public static function weightPortions(int $actualWeight, Product $product): float
  {
    return (float) DeliveryReturn::collectedPortions(null, $actualWeight, ProductConfirm::getStepGrams($product));
  }

  /**
   * Снапшот позиции для выдачи наружу (GraphQL OrderItem.price_tiers):
   * нормализованный снапшот; пустышка «снапшот снят» (tiers: [], её пишет
   * purchase_back при смене магазина заказа) как есть; null у позиций без
   * снапшота и при битых данных.
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
