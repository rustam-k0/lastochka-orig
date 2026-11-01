<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Order;
use App\Services\Pricing\OrderDeliveryPriceResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-280: доставка заказа после оформления. Снимок есть: ступень по фактической
 * сумме товаров до промокода и бонусов, не дороже цены оформления. Снимка нет:
 * прежний порог первой активной зоны магазина по сумме после скидки.
 */
class OrderDeliveryPriceResolverTest extends TestCase
{
  use DatabaseTransactions, DeliveryPriceRangesFixtures;

  private int $shopId;
  private int $clientId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
  }

  private function order(array $attrs): Order
  {
    return Order::findOrFail($this->insertOrder($this->shopId, $this->clientId, $attrs));
  }

  private function resolve(Order $order, float $itemsSubtotal, float $discount = 0.0): int
  {
    return (new OrderDeliveryPriceResolver())->resolve($order, $itemsSubtotal, $discount);
  }

  public function test_snapshot_price_goes_down_when_amount_grows(): void
  {
    // Оформлен на 1500 → доставка 100; по факту 3200 → бесплатно
    $order = $this->order(['delivery_price' => 100, 'delivery_price_snapshot' => $this->snapshotJson(100)]);

    $this->assertSame(0, $this->resolve($order, 3200));
  }

  public function test_snapshot_price_is_capped_by_checkout_price(): void
  {
    // Оформлен на 1500 → 100; по факту 900: по лесенке 200, но не дороже оформления
    $order = $this->order(['delivery_price' => 100, 'delivery_price_snapshot' => $this->snapshotJson(100)]);

    $this->assertSame(100, $this->resolve($order, 900));
  }

  public function test_snapshot_lower_bound_is_inclusive(): void
  {
    // Оформлен на 900 → 200; по факту ровно 1000 → ступень «от 1000»
    $order = $this->order(['delivery_price' => 200, 'delivery_price_snapshot' => $this->snapshotJson(200)]);

    $this->assertSame(100, $this->resolve($order, 1000));
    $this->assertSame(200, $this->resolve($order, 999.99));
  }

  public function test_snapshot_compares_amount_before_promocode(): void
  {
    // Товары 3000, промокод −300: база ступеней до промокода, доставка бесплатна
    $order = $this->order(['delivery_price' => 100, 'discount' => 300, 'delivery_price_snapshot' => $this->snapshotJson(100)]);

    $this->assertSame(0, $this->resolve($order, 3000, 300));
  }

  public function test_snapshot_ignores_live_zone_threshold(): void
  {
    // СДЭК/Почта: ступеней нет; порог зоны магазина 500 доставку не обнуляет
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);
    $order = $this->order(['delivery_price' => 350, 'delivery_price_snapshot' => $this->snapshotJson(350, [], 350)]);

    $this->assertSame(350, $this->resolve($order, 10000));
  }

  public function test_without_snapshot_uses_active_zone_threshold_after_discount(): void
  {
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);
    $order = $this->order(['delivery_price' => 300]);

    $this->assertSame(0, $this->resolve($order, 600), 'порог 500 достигнут');
    $this->assertSame(300, $this->resolve($order, 600, 200), 'после скидки 400 < 500: прежняя база');
  }

  public function test_without_snapshot_ignores_inactive_zone(): void
  {
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500, 'is_active' => 0]);
    $order = $this->order(['delivery_price' => 300]);

    $this->assertSame(300, $this->resolve($order, 600));
  }

  public function test_without_snapshot_never_raises_price(): void
  {
    // Прежний путь только обнуляет: сумма ниже порога — доставка как была
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);
    $order = $this->order(['delivery_price' => 0]);

    $this->assertSame(0, $this->resolve($order, 100));
  }

  public function test_broken_snapshot_falls_back_to_previous_logic(): void
  {
    $this->insertZone($this->shopId, 300, null, ['free_delivery_from' => 500]);
    $order = $this->order(['delivery_price' => 300, 'delivery_price_snapshot' => json_encode(['base_price' => 200])]);

    $this->assertSame(0, $this->resolve($order, 600));
  }
}
