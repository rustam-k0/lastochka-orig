<?php

namespace App\Services;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Status;
use App\Services\Pricing\OrderItemTierPricing;
use App\Support\DeliveryReturn;
use Illuminate\Support\Collection;

/**
 * Возвраты позиций курьером при доставке: единый расчёт фактически
 * доставленной суммы заказа.
 *
 * Раньше сумма пересчитывалась только в DeliveryFinish, поэтому курьер во
 * время расчёта с клиентом видел сумму без учёта уже отмеченных возвратов.
 */
final class CourierReturnService
{
  /**
   * Фактический вес позиции. Для штучного товара actual_weight игнорируется:
   * на штучных он бывает залипшим от старых версий мобилки сборщика (MID-246).
   */
  public static function effectiveActualWeight(OrderItem $order_item): ?int
  {
    $product = $order_item->product;

    if ($product && !$product->isWeightBased()) {
      return null;
    }

    return $order_item->actual_weight ?? $order_item->assemblyItem?->actual_weight;
  }

  /**
   * Позиции заказа, участвующие в сумме. Читаем напрямую, минуя
   * courier-фильтр Order::orderItems, который скрывает отменённые позиции.
   *
   * @return Collection<int, OrderItem>
   */
  public static function billableItems(Order $order): Collection
  {
    return OrderItem::with(['product', 'assemblyItem', 'supplements'])
      ->where('order_id', $order->id)
      ->get()
      ->reject(fn (OrderItem $item) => $item->status_id === Status::ORDER_ITEM_CANCELED)
      ->values();
  }

  /**
   * Сумма фактически доставленного по позициям (с учётом returned_quantity).
   *
   * @param Collection<int, OrderItem> $items
   */
  public static function deliveredItemsTotal(Collection $items): float
  {
    $total = 0.0;

    // MID-308: котировка по снапшоту позиции (от флага модуля не зависит)
    $tierPricing = new OrderItemTierPricing();

    foreach ($items as $order_item) {
      $actualWeight = self::effectiveActualWeight($order_item);
      $stepGrams = $order_item->product
        ? ProductConfirm::getStepGrams($order_item->product)
        : 1;

      $collected = DeliveryReturn::collectedPortions($order_item->quantity, $actualWeight, $stepGrams);
      $returned = (int) ($order_item->returned_quantity ?? 0);

      $unitPrice = (float) ($order_item->price_during_order ?? $order_item->price);

      // MID-308: клиент отказался от части штучной позиции со снапшотом лесенки:
      // цена за единицу берётся по ступени для ДОСТАВЛЕННОГО количества
      // (5 × 199 → вернули 2 → 3 × 249). Без снапшота, без возврата и у весовой
      // позиции котировки нет: остаётся сохранённая цена.
      $deliveredQuote = $tierPricing->deliveredQuote($order_item, $actualWeight, $collected, $returned);

      if ($deliveredQuote !== null) {
        $unitPrice = $deliveredQuote->unitPrice;
      }

      // Снимок итога взвешенной строки пишет AssemblyFinish в order_items.price
      // (маркер — order_items.actual_weight); до него — пересчёт по шагу.
      $weighedLineTotal = $order_item->actual_weight !== null ? (float) $order_item->price : null;

      $deliveredLine = DeliveryReturn::deliveredLineTotal($unitPrice, $actualWeight, $stepGrams, $collected, $returned, $weighedLineTotal);

      // Доставленные supplements — пропорционально доставленным порциям
      $deliveredPortions = max(0, $collected - $returned);
      $supplementsTotal = $order_item->supplements->sum('amount');

      $total += $deliveredLine + $supplementsTotal * $deliveredPortions;
    }

    return round($total, 2);
  }

  /**
   * Пересчитать сумму заказа по фактически доставленному составу.
   * Вызывается сразу при отметке/отмене возврата, чтобы курьер видел
   * актуальную сумму к оплате до завершения доставки.
   *
   * Событие в шину не публикуем: промежуточные состояния доставки витрине не
   * нужны, финальная сумма уедет из DeliveryFinish.
   */
  public static function recalcOrderAmount(Order $order): void
  {
    $order->loadMissing('shop');

    $itemsTotal = self::deliveredItemsTotal(self::billableItems($order));
    $totals = AssemblyFinish::recalcOrderTotals($order, $itemsTotal);

    Order::whereId($order->id)->update($totals);
    $order->refresh();
  }
}
