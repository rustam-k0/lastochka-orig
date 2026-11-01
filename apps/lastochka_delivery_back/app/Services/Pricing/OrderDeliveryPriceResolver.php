<?php

namespace App\Services\Pricing;

use App\Models\ClientOrderInfo;
use App\Models\Order;
use App\Models\ShopZone;
use App\Models\Type;
use App\Services\ShopZoneMatcher;
use Illuminate\Support\Facades\Schema;

/**
 * Стоимость доставки заказа после оформления (MID-280): единая точка для
 * завершения сборки, списания оплаты сборщиком, возврата у двери и суммы
 * родителя по подзаказам.
 *
 * Снимок условий доставки есть (orders.delivery_price_snapshot, приходит с витрины
 * событием заказа): ступень лесенки по фактической сумме товаров до промокода и
 * бонусов, но не дороже доставки при оформлении. Снимка нет (заказ оформлен до
 * выкладки MID-280, событие старой витрины): прежний пересчёт без изменений.
 */
final class OrderDeliveryPriceResolver
{
  /**
   * @param float $itemsSubtotal сумма товаров по факту, до промокода и бонусов
   * @param float $discount скидка по промокоду; нужна только прежнему пересчёту
   */
  public function resolve(Order $order, float $itemsSubtotal, float $discount): int
  {
    $snapshot = DeliveryPriceSnapshot::fromArray($order->delivery_price_snapshot);

    if ($snapshot !== null) {
      return $snapshot->priceFor($itemsSubtotal);
    }

    return $this->legacyPrice($order, $itemsSubtotal, $discount);
  }

  /**
   * Прежний пересчёт (до MID-280), перенесён из AssemblyFinish без изменений:
   * порог бесплатной доставки первой активной зоны магазина против суммы товаров
   * после скидки; только обнуление, иначе доставка заказа как была.
   */
  private function legacyPrice(Order $order, float $itemsSubtotal, float $discount): int
  {
    $deliveryPrice = (int) ($order->delivery_price ?? 0);

    // Пересчёт доставки по порогу бесплатной доставки зоны магазина
    $freeDeliveryFrom = \Illuminate\Support\Facades\DB::table('shop_zones')
      ->where('shop_id', $order->shop_id)
      ->where('is_active', 1)
      ->whereNull('deleted_at')
      ->whereNotNull('free_delivery_from')
      ->value('free_delivery_from');

    if ($freeDeliveryFrom !== null && $freeDeliveryFrom > 0) {
      $totalAfterDiscount = $itemsSubtotal - $discount;
      if ($totalAfterDiscount >= $freeDeliveryFrom) {
        $deliveryPrice = 0;
      }
    }

    return $deliveryPrice;
  }

  /**
   * Снимок условий доставки после смены магазина заказа: ступени зоны нового магазина
   * по адресу заказа, checkout_price = текущая доставка заказа (при смене магазина она
   * не пересчитывается). Доставка фиксируется (ступеней нет), если у заказа не было
   * снимка, способ не курьерский (самовывоз, СДЭК, Почта) или адрес не попал ни в одну
   * активную зону нового магазина. null: снимок не трогаем (подзаказ; колонки ещё нет
   * в окне выкладки).
   *
   * @return array{base_price: int, ranges: array<int, array{from_amount: int, price: int}>, checkout_price: int}|null
   */
  public function snapshotForShop(Order $order, int $shopId): ?array
  {
    if ($order->parent_order_id !== null || !Schema::hasColumn('orders', 'delivery_price_snapshot')) {
      return null;
    }

    $currentPrice = (int) ($order->delivery_price ?? 0);
    $zone = null;

    if (DeliveryPriceSnapshot::fromArray($order->delivery_price_snapshot) !== null
      && in_array((int) $order->type_id, [Type::DELIVERY_COURIER, Type::DELIVERY_YANDEX], true)
    ) {
      $zone = $this->zoneForAddress($order, $shopId);
    }

    if ($zone === null) {
      return DeliveryPriceSnapshot::forCheckout(DeliveryPriceLadder::fromRanges($currentPrice, []), $currentPrice)->toArray();
    }

    $zoneLadder = $this->zoneLadder($zone);

    // Яндекс: цену называет Яндекс, из лесенки зоны остаётся только бесплатная ступень
    $ladder = $zone->delivery_type === 'yandex'
      ? DeliveryPriceLadder::fromFreeDeliveryFrom($currentPrice, $zoneLadder->freeFrom())
      : $zoneLadder;

    return DeliveryPriceSnapshot::forCheckout($ladder, $currentPrice)->toArray();
  }

  /**
   * Активная зона магазина, в полигон которой попадает адрес заказа. Несколько зон:
   * с минимальной ценой доставки, как выбирает зону slots-api при оформлении
   * (при равной цене меньший id).
   */
  private function zoneForAddress(Order $order, int $shopId): ?ShopZone
  {
    $clientInfo = ClientOrderInfo::query()->where('order_id', $order->id)->first();

    if (!$clientInfo || !$clientInfo->latitude || !$clientInfo->longitude) {
      return null;
    }

    $matcher = new ShopZoneMatcher();
    $matching = null;

    $zones = ShopZone::with('coordinates')
      ->where('shop_id', $shopId)
      ->where('is_active', true)
      ->orderBy('id')
      ->get();

    foreach ($zones as $zone) {
      if (!$matcher->isPointInZone($zone, (float) $clientInfo->latitude, (float) $clientInfo->longitude)) {
        continue;
      }

      if ($matching === null || $zone->delivery_price < $matching->delivery_price) {
        $matching = $zone;
      }
    }

    return $matching;
  }

  /**
   * Лесенка зоны: её ступени, если они есть; иначе пара «цена зоны + порог бесплатной
   * доставки» (зона сохранена до MID-280 и с тех пор не пересохранялась).
   */
  private function zoneLadder(ShopZone $zone): DeliveryPriceLadder
  {
    $ranges = DeliveryPriceLadder::normalize($zone->delivery_price_ranges);

    if ($ranges !== null && $ranges !== []) {
      return DeliveryPriceLadder::fromRanges((int) $zone->delivery_price, $ranges);
    }

    return DeliveryPriceLadder::fromFreeDeliveryFrom(
      (int) $zone->delivery_price,
      $zone->free_delivery_from !== null ? (int) $zone->free_delivery_from : null
    );
  }
}
