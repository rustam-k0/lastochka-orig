<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Исходящее событие заказа (MID-308).
 */
class OrderEventOutboundTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $orderId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->orderId = $this->insertOrder($this->insertShop(), $this->insertClient(), [
      'status_id' => Status::ORDER_ASSEMBLED,
      'amount' => 1000,
      'slot_start_date' => now(),
      'slot_end_date' => now(),
    ]);
  }

  /** @return array<int, array<string, mixed>> элементы items[] события */
  private function eventItems(): array
  {
    $order = Order::findOrFail($this->orderId)->load(['orderItems.product', 'orderItems.originProduct']);

    return app(OrderEventTransformer::class)->toEvent($order)['items'];
  }

  private function eventItem(int $itemId): array
  {
    $item = collect($this->eventItems())->firstWhere('id', (string) $itemId);
    $this->assertNotNull($item, 'позиция не попала в событие');

    return $item;
  }

  public function test_collector_added_item_carries_snapshot_and_unit_price_old(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 2, 'price' => 498, 'price_during_order' => 249, 'price_old' => 279,
      'is_eligible' => 0, 'is_supplemented' => 1,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame('added', $item['item_status']);
    $this->assertSame(249.0, $item['unit_price']);
    $this->assertSame(498.0, $item['line_total']);
    $this->assertSame(279.0, $item['unit_price_old']);
    $this->assertEquals([
      'base_price' => 279.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 279.0],
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
      ],
    ], $item['price_tiers']);
  }

  public function test_replacement_payload_carries_fresh_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $originProductId = $this->insertProduct();
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'origin_product_id' => $originProductId, 'origin_price' => 150, 'origin_quantity' => 3,
      'quantity' => 3, 'price' => 300, 'price_during_order' => 100, 'price_old' => 120, 'is_eligible' => 0,
      'price_tiers' => $this->snapshotJson([['min_quantity' => 1, 'price' => 120], ['min_quantity' => 3, 'price' => 100]], 120.0),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $items = collect($this->eventItems());
    $replacement = $items->firstWhere('id', (string) $itemId);
    $origin = $items->firstWhere('id', $itemId . '_origin');

    $this->assertSame('added', $replacement['item_status']);
    $this->assertSame(100.0, $replacement['unit_price']);
    $this->assertSame(300.0, $replacement['line_total']);
    $this->assertSame(120.0, $replacement['unit_price_old']);
    $this->assertEquals(120.0, $replacement['price_tiers']['base_price']);
    $this->assertCount(2, $replacement['price_tiers']['tiers']);

    $this->assertSame('replaced', $origin['item_status']);
    $this->assertArrayNotHasKey('price_tiers', $origin);
    $this->assertArrayNotHasKey('unit_price_old', $origin);
  }

  public function test_storefront_item_with_snapshot_carries_the_key_as_is(): void
  {
    $this->setPriceTiersFlag(true);
    $snapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 3, 'price' => 747, 'price_during_order' => 249, 'price_old' => 279, 'is_eligible' => 0,
      'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame('confirmed', $item['item_status']);
    $this->assertSame(249.0, $item['unit_price'], 'цена уже пересчитана AssemblyFinish');
    $this->assertSame(747.0, $item['line_total']);
    $this->assertSame(279.0, $item['unit_price_old']);
    $this->assertEquals(json_decode($snapshot, true), $item['price_tiers'], 'ключ несёт любая позиция с непустой колонкой, значение как есть');
  }

  public function test_stub_snapshot_travels_as_is_and_item_is_priced_by_old_formula(): void
  {
    // Пустышка после смены магазина (purchase_back): ключ в событии есть, витрина
    // снимет свой снапшот; расчёты идут как у позиции без снапшота
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'returned_quantity' => 2, 'price' => 1900, 'price_during_order' => 380,
      'price_tiers' => $this->snapshotJson([], 380.0),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertEquals([], $item['price_tiers']['tiers']);
    $this->assertEquals(380.0, $item['price_tiers']['base_price']);
    $this->assertArrayNotHasKey('unit_price_old', $item);
    $this->assertSame(380.0, $item['unit_price']);
    $this->assertSame(1140.0, $item['line_total'], 'прежняя формула: 3 × 380');
  }

  public function test_partial_return_uses_tier_of_delivered_quantity(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'returned_quantity' => 2, 'price' => 995, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame(3, $item['quantity']);
    $this->assertSame(2, $item['returned_quantity']);
    $this->assertSame(249.0, $item['unit_price']);
    $this->assertSame(249.0, $item['unit_price_effective']);
    $this->assertSame(747.0, $item['line_total']);
    $this->assertSame(279.0, $item['unit_price_old']);
  }

  public function test_return_down_to_first_tier_clears_unit_price_old(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'returned_quantity' => 4, 'price' => 995, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame(279.0, $item['unit_price']);
    $this->assertSame(279.0, $item['line_total']);
    $this->assertNull($item['unit_price_old']);
  }

  public function test_item_without_snapshot_keeps_payload_keys(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 2, 'price' => 200, 'price_during_order' => 100, 'is_supplemented' => 1,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertArrayNotHasKey('price_tiers', $item);
    $this->assertArrayNotHasKey('unit_price_old', $item);
    $this->assertSame(200.0, $item['line_total']);
  }

  public function test_existing_snapshot_travels_and_prices_even_when_flag_is_off(): void
  {
    // Передача снапшота и пересчёт по нему идут по наличию снапшота, без флага
    $this->setPriceTiersFlag(false);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'returned_quantity' => 2, 'price' => 995, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0, 'is_supplemented' => 1,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertArrayHasKey('price_tiers', $item);
    $this->assertSame(249.0, $item['unit_price']);
    $this->assertSame(747.0, $item['line_total']);
    $this->assertSame(279.0, $item['unit_price_old']);
  }

  public function test_returned_item_without_snapshot_keeps_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'returned_quantity' => 2, 'price' => 995, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertArrayNotHasKey('price_tiers', $item);
    $this->assertArrayNotHasKey('unit_price_old', $item);
    $this->assertSame(199.0, $item['unit_price']);
    $this->assertSame(597.0, $item['line_total'], 'прежняя формула: 3 × 199');
  }

  /**
   * Д1 (довесок по итогам ревью задач 14–15): позиция без снапшота — полный
   * набор ключей payload зафиксирован явно, а не только отсутствие двух ключей.
   */
  public function test_item_without_snapshot_has_full_legacy_key_set(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 2, 'price' => 200, 'price_during_order' => 100,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame([
      'id', 'source', 'product_id', 'product_source', 'product_name', 'quantity',
      'unit_price', 'unit_price_effective', 'line_subtotal', 'line_discount_total',
      'line_total', 'item_status', 'returned_quantity',
      // Размер порции в г/мл/шт — для выгрузки невзвешенной весовой позиции в 1С
      'portion_size',
    ], array_keys($item));
  }

  /**
   * Д2: весовая позиция со снапшотом. Котировка по ступени для доставленного
   * количества не применяется к весовым (OrderItemTierPricing::deliveredQuote()
   * возвращает null при actual_weight !== null) — unit_price/line_total по
   * прежней формуле (line_total = снапшот AssemblyFinish, order_items.price).
   * price_tiers приложен (колонка не NULL), unit_price_old — тоже: OrderEventTransformer
   * добавляет ключ по $tierSnapshot !== null независимо от типа позиции (строки
   * ~1058-1064), значение берёт из order_items.price_old.
   */
  public function test_weighted_item_with_snapshot_uses_legacy_formula_and_carries_price_tiers(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $itemId = $this->insertOrderItem($this->orderId, $productId, [
      'quantity' => 5, 'actual_weight' => 960, 'price' => 432, 'price_during_order' => 90,
      'price_old' => 100, 'is_eligible' => 0,
      'price_tiers' => $this->snapshotJson([['min_quantity' => 5, 'price' => 90]], 100.0),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame(90.0, $item['unit_price'], 'весовая позиция: котировка по доставленному количеству не применяется');
    $this->assertSame(432.0, $item['line_total'], 'снапшот AssemblyFinish (order_items.price), не пересчёт по текущему шагу');
    $this->assertArrayHasKey('price_tiers', $item);
    $this->assertSame(100.0, $item['unit_price_old'], 'зачёркнутая цена берётся из order_items.price_old при наличии снапшота');
  }

  /**
   * Д3: полный возврат у двери (доставлено 0) у штучной позиции со снапшотом.
   * line_total = 0, отрицательных значений нет, исключений нет.
   */
  public function test_full_return_of_tiered_piece_item_zeroes_line_total(): void
  {
    $this->setPriceTiersFlag(true);
    $itemId = $this->insertOrderItem($this->orderId, $this->insertProduct(), [
      'quantity' => 5, 'returned_quantity' => 5, 'price' => 995, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0,
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE),
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    $item = $this->eventItem($itemId);

    $this->assertSame(0, $item['quantity'], 'доставлено 0: collected 5 − returned 5');
    $this->assertSame(0.0, $item['line_subtotal']);
    $this->assertSame(0.0, $item['line_total']);
    $this->assertGreaterThanOrEqual(0.0, $item['unit_price'], 'не должно уходить в минус');
    $this->assertGreaterThanOrEqual(0.0, $item['line_total'], 'не должно уходить в минус');
  }
}
