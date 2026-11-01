<?php

namespace App\Aggregator\Services;

use App\Aggregator\Events\OrderPlaced;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductOffer;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Расщепление заказа на дочерние по магазину-поставщику (aggregator-режим, FOODLINK-10874).
 *
 * Слушает OrderPlaced (регистрируется в AggregatorServiceProvider только при
 * aggregator_enabled). Каждый child-order = один shop_id; сборщик/курьер работают
 * с обычным Order/OrderItem. См. docs/aggregator-mode.md §6.5.
 *
 * Вся aggregator-специфичная логика заказа изолирована здесь — общий
 * processOrderItems в OrderEventTransformer не трогаем (§3.1).
 */
class OrderAggregatorSplitter
{
  public function handle(OrderPlaced $event): void
  {
    $order = $event->order;

    if ($order->parent_order_id !== null) {
      return; // сам дочерний — не расщепляем
    }
    if ($order->split_strategy !== null) {
      return; // уже расщеплён
    }
    if ($order->childOrders()->exists()) {
      // zone-split уже создал дочерние (комбинация aggregator+marketplace не
      // поддерживается в MVP, §3.2) — не вмешиваемся.
      return;
    }

    // Обогатить позиции данными из входящего payload (offer_id / external_shop_code).
    // Изолировано здесь, чтобы не править общий processOrderItems.
    $this->enrichItemsFromPayload($order, $event->payload);

    $order->load('orderItems.product');
    $itemsByShop = $this->groupItemsByShop($order);

    // Позиции, для которых магазин не резолвится (offer_id / meta / product.shop_id —
    // всё пусто), groupBy кладёт под пустой ключ. Их НЕ переносим в дочерние (иначе
    // получим child с shop_id=null, не маршрутизируемый на выдачу) — оставляем на родителе.
    $unassigned = $itemsByShop->get('') ?? $itemsByShop->get(0);
    $itemsByShop = $itemsByShop->reject(fn ($items, $key) => $key === '' || $key === null || (int) $key === 0);

    if ($unassigned !== null) {
      Log::channel('single')->warning('[aggregator] order has items without resolvable shop, left on parent', [
        'parent_order_id' => $order->id,
        'unassigned_items' => $unassigned->pluck('id')->all(),
      ]);
    }

    if ($itemsByShop->isEmpty()) {
      return;
    }

    if ($itemsByShop->count() === 1 && $unassigned === null) {
      // Весь заказ от одного магазина — child'ы не нужны, просто размечаем.
      $order->update([
        'split_strategy' => 'shop',
        'shop_id' => (int) $itemsByShop->keys()->first(),
      ]);
      return;
    }

    DB::transaction(function () use ($order, $itemsByShop) {
      $order->update(['split_strategy' => 'shop']);

      $subNumber = 1;
      foreach ($itemsByShop as $shopId => $items) {
        $child = Order::create([
          'uuid' => $order->uuid . '-' . $subNumber,
          'parent_order_id' => $order->id,
          'sub_number' => $subNumber,
          'shop_id' => (int) $shopId,
          'split_strategy' => 'shop',
          'client_id' => $order->client_id,
          'type_id' => $order->type_id,
          'status_id' => $order->status_id,
          'payment_type_id' => $order->payment_type_id,
          'slot_start_date' => $order->slot_start_date,
          'slot_end_date' => $order->slot_end_date,
          // MID-285: подзаказ доставляется в том же окне, что и родитель
          'payment_held_at' => $order->payment_held_at,
          // amount = сумма line-total'ов (OrderItem.price — цена за единицу), как в zone-split.
          'amount' => $items->sum(fn (OrderItem $item) => ($item->price ?? 0) * ($item->quantity ?? 1)),
        ]);

        foreach ($items as $item) {
          $item->update(['order_id' => $child->id]);
        }

        $subNumber++;
      }

      // Родитель становится чисто координационным (если все позиции разнесены).
      $order->update(['shop_id' => null]);

      Log::channel('single')->info('[aggregator] order split by shop', [
        'parent_order_id' => $order->id,
        'children' => $itemsByShop->count(),
      ]);
    });
  }

  /**
   * Сгруппировать позиции заказа по магазину-поставщику (батч-резолв без N+1).
   * Precedence: offer_id → meta.external_shop_code → product.shop_id.
   *
   * @return Collection<int|string, Collection<int, OrderItem>>
   */
  private function groupItemsByShop(Order $order): Collection
  {
    $items = $order->orderItems;

    // Пре-загрузка справочников один раз (вместо запроса на каждую позицию).
    $offerIds = $items->pluck('offer_id')->filter()->unique();
    $offerShopMap = $offerIds->isEmpty()
      ? collect()
      : ProductOffer::whereIn('id', $offerIds)->pluck('shop_id', 'id');

    $codes = $items
      ->map(fn (OrderItem $i) => is_array($i->meta) ? ($i->meta['external_shop_code'] ?? null) : null)
      ->filter()
      ->unique();
    $codeShopMap = $codes->isEmpty()
      ? collect()
      : Shop::whereIn('code', $codes)->pluck('id', 'code');

    return $items->groupBy(fn (OrderItem $item) => $this->resolveShopId($item, $offerShopMap, $codeShopMap));
  }

  /**
   * @param \Illuminate\Support\Collection<int, int> $offerShopMap  offer_id => shop_id
   * @param \Illuminate\Support\Collection<string, int> $codeShopMap shop_code => shop_id
   */
  private function resolveShopId(OrderItem $item, Collection $offerShopMap, Collection $codeShopMap): ?int
  {
    if ($item->offer_id && $offerShopMap->has($item->offer_id)) {
      return (int) $offerShopMap->get($item->offer_id);
    }

    $externalShopCode = is_array($item->meta) ? ($item->meta['external_shop_code'] ?? null) : null;
    if ($externalShopCode && $codeShopMap->has($externalShopCode)) {
      return (int) $codeShopMap->get($externalShopCode);
    }

    return $item->product?->shop_id !== null ? (int) $item->product->shop_id : null;
  }

  /**
   * Перенести из payload в позиции заказа offer_id + meta (marketplace_product_id,
   * external_shop_code). Сопоставление по индексу — processOrderItems создаёт позиции
   * в порядке payload['items']. Если число позиций не совпало — пропускаем обогащение
   * (групировка откатится к product.shop_id).
   *
   * @param array<string, mixed> $payload
   */
  private function enrichItemsFromPayload(Order $order, array $payload): void
  {
    $payloadItems = $payload['items'] ?? null;
    if (!is_array($payloadItems) || empty($payloadItems)) {
      return;
    }

    $orderItems = $order->orderItems()->orderBy('id')->get();
    if ($orderItems->count() !== count($payloadItems)) {
      return;
    }

    $payloadItems = array_values($payloadItems);

    // Пре-загрузка справочников вне цикла (без N+1).
    $codes = collect($payloadItems)->pluck('external_shop_code')->filter()->unique();
    $codeShopMap = $codes->isEmpty()
      ? collect()
      : Shop::whereIn('code', $codes)->pluck('id', 'code');

    $productIds = $orderItems->pluck('product_id')->filter()->unique();
    $shopIds = $codeShopMap->values();
    $offerMap = ($productIds->isEmpty() || $shopIds->isEmpty())
      ? collect()
      : ProductOffer::whereIn('local_product_id', $productIds)
        ->whereIn('shop_id', $shopIds)
        ->get()
        ->keyBy(fn (ProductOffer $o) => $o->shop_id . '-' . $o->local_product_id)
        ->map(fn (ProductOffer $o) => $o->id);

    foreach ($orderItems as $index => $item) {
      $pItem = $payloadItems[$index] ?? [];
      $externalShopCode = $pItem['external_shop_code'] ?? null;
      $marketplaceProductId = $pItem['marketplace_product_id'] ?? null;

      if ($externalShopCode === null && $marketplaceProductId === null) {
        continue;
      }

      $meta = is_array($item->meta) ? $item->meta : [];
      if ($externalShopCode !== null) {
        $meta['external_shop_code'] = $externalShopCode;
      }
      if ($marketplaceProductId !== null) {
        $meta['marketplace_product_id'] = $marketplaceProductId;
      }

      $update = ['meta' => $meta];

      // Проставить offer_id из пре-загруженной map'ы: оффер поставщика на локальный товар.
      if ($externalShopCode !== null && $item->offer_id === null && $codeShopMap->has($externalShopCode)) {
        $shopId = $codeShopMap->get($externalShopCode);
        $offerId = $offerMap->get($shopId . '-' . $item->product_id);
        if ($offerId) {
          $update['offer_id'] = $offerId;
        }
      }

      $item->update($update);
    }
  }
}
