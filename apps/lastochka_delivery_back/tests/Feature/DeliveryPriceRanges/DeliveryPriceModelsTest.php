<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Order;
use App\Models\ShopZone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-280: снимок условий доставки в заказе и ступени зоны читаются моделями
 * курьерки как массивы и пишутся обычным update()/create().
 */
class DeliveryPriceModelsTest extends TestCase
{
  use DatabaseTransactions, DeliveryPriceRangesFixtures;

  public function test_order_snapshot_is_cast_to_array_and_fillable(): void
  {
    $orderId = $this->insertOrder($this->insertShop(), $this->insertClient(), [
      'delivery_price_snapshot' => $this->snapshotJson(100),
    ]);

    $order = Order::findOrFail($orderId);

    $this->assertEquals([
      'base_price' => 200,
      'ranges' => self::$TASK_RANGES,
      'checkout_price' => 100,
    ], $order->delivery_price_snapshot);

    $order->update(['delivery_price_snapshot' => ['base_price' => 150, 'ranges' => [], 'checkout_price' => 150]]);

    $this->assertEquals(
      ['base_price' => 150, 'ranges' => [], 'checkout_price' => 150],
      json_decode($this->orderRow($orderId)->delivery_price_snapshot, true)
    );
  }

  public function test_order_without_snapshot_reads_null(): void
  {
    $order = Order::findOrFail($this->insertOrder($this->insertShop(), $this->insertClient()));

    $this->assertNull($order->delivery_price_snapshot);
  }

  public function test_zone_ranges_are_cast_to_array_and_fillable(): void
  {
    $zone = ShopZone::create([
      'shop_id' => $this->insertShop(),
      'name' => 'Зона',
      'delivery_price' => 200,
      'min_order_price' => 0,
      'delivery_price_ranges' => self::$TASK_RANGES,
    ]);

    $this->assertEquals(self::$TASK_RANGES, $zone->fresh()->delivery_price_ranges);
  }
}
