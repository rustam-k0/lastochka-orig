<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Order;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MID-280: смена магазина заказа (POST /backend-delivery/order/shop/change, зовёт
 * purchase_back OrderShopUpdate) пересобирает снимок условий доставки по зоне нового
 * магазина: ступени зоны по адресу заказа, checkout_price = текущая доставка заказа.
 * Сама доставка при смене магазина не пересчитывается.
 */
class ShopChangeDeliverySnapshotTest extends TestCase
{
  use DatabaseTransactions, DeliveryPriceRangesFixtures;

  private const NEW_ZONE_RANGES = [
    ['from_amount' => 2000, 'price' => 150],
    ['from_amount' => 5000, 'price' => 0],
  ];

  private int $oldShopId;
  private int $newShopId;
  private int $clientId;

  protected function setUp(): void
  {
    parent::setUp();

    config(['services.system.token' => 'test-token']);

    $this->oldShopId = $this->insertShop();
    $this->newShopId = $this->insertShop();
    $this->clientId = $this->insertClient();
  }

  /** Заказ старого магазина с доставкой 100 и снимком его зоны; адрес внутри полигона. */
  private function order(array $attrs = [], bool $withAddress = true): int
  {
    $orderId = $this->insertOrder($this->oldShopId, $this->clientId, array_merge([
      'delivery_price' => 100,
      'delivery_price_snapshot' => $this->snapshotJson(100),
    ], $attrs));

    if ($withAddress) {
      $this->insertClientInfo($orderId);
    }

    return $orderId;
  }

  private function changeShop(int $orderId): void
  {
    $this->post('/backend-delivery/order/shop/change', [
      'token' => 'test-token',
      'order_id' => $orderId,
      'shop_id' => $this->newShopId,
    ])->assertJson(['code' => 200]);
  }

  private function snapshot(int $orderId): ?array
  {
    $raw = $this->orderRow($orderId)->delivery_price_snapshot;

    return $raw !== null ? json_decode($raw, true) : null;
  }

  public function test_snapshot_is_rebuilt_from_new_shop_zone(): void
  {
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $orderId = $this->order();

    $this->changeShop($orderId);

    $order = $this->orderRow($orderId);
    $this->assertSame($this->newShopId, (int) $order->shop_id);
    $this->assertSame(100, (int) $order->delivery_price, 'доставка при смене магазина не пересчитывается');
    $this->assertEquals([
      'base_price' => 250,
      'ranges' => self::NEW_ZONE_RANGES,
      'checkout_price' => 100,
    ], $this->snapshot($orderId));
  }

  public function test_zone_without_ranges_uses_free_delivery_threshold(): void
  {
    // Зона сохранена до MID-280: ступеней нет, есть порог бесплатной доставки
    $this->insertZone($this->newShopId, 250, null, ['free_delivery_from' => 4000]);
    $orderId = $this->order();

    $this->changeShop($orderId);

    $this->assertEquals([
      'base_price' => 250,
      'ranges' => [['from_amount' => 4000, 'price' => 0]],
      'checkout_price' => 100,
    ], $this->snapshot($orderId));
  }

  public function test_cheapest_active_matching_zone_wins(): void
  {
    // Неактивная зона дешевле всех, но в расчёте доставки не участвует
    $this->insertZone($this->newShopId, 50, [['from_amount' => 100, 'price' => 0]], ['is_active' => 0]);
    $this->insertZone($this->newShopId, 300, [['from_amount' => 1000, 'price' => 0]]);
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $orderId = $this->order();

    $this->changeShop($orderId);

    $this->assertSame(250, $this->snapshot($orderId)['base_price']);
    $this->assertEquals(self::NEW_ZONE_RANGES, $this->snapshot($orderId)['ranges']);
  }

  public function test_yandex_zone_keeps_only_free_range_and_current_price(): void
  {
    $this->insertZone($this->newShopId, 300, [['from_amount' => 3000, 'price' => 0]], ['delivery_type' => 'yandex']);
    $orderId = $this->order(['type_id' => Type::DELIVERY_YANDEX]);

    $this->changeShop($orderId);

    $this->assertEquals([
      'base_price' => 100,
      'ranges' => [['from_amount' => 3000, 'price' => 0]],
      'checkout_price' => 100,
    ], $this->snapshot($orderId));
  }

  public function test_address_outside_new_shop_zones_fixes_delivery(): void
  {
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $orderId = $this->order([], false);
    $this->insertClientInfo($orderId, 10.0, 10.0);

    $this->changeShop($orderId);

    $this->assertEquals(['base_price' => 100, 'ranges' => [], 'checkout_price' => 100], $this->snapshot($orderId));
  }

  public function test_order_without_snapshot_gets_fixed_delivery(): void
  {
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $orderId = $this->order(['delivery_price_snapshot' => null]);

    $this->changeShop($orderId);

    $this->assertEquals(['base_price' => 100, 'ranges' => [], 'checkout_price' => 100], $this->snapshot($orderId));
  }

  public function test_pickup_order_gets_fixed_delivery(): void
  {
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $orderId = $this->order([
      'type_id' => Type::DELIVERY_PICKUP,
      'delivery_price' => 0,
      'delivery_price_snapshot' => $this->snapshotJson(0, [], 0),
    ]);

    $this->changeShop($orderId);

    $this->assertEquals(['base_price' => 0, 'ranges' => [], 'checkout_price' => 0], $this->snapshot($orderId));
  }

  public function test_storefront_update_does_not_roll_back_rebuilt_snapshot(): void
  {
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $orderId = $this->order();

    $this->changeShop($orderId);

    // Очередной order.updated витрины несёт снимок оформления (старый магазин).
    // Путь UPDATE консьюмера пакета: fromEvent → beforeUpdate → forceFill()->save()
    $order = Order::findOrFail($orderId);
    $event = [
      'id' => $order->uuid,
      'shop_code' => 'default',
      'status' => 'created',
      'payment_type' => 'card',
      'shipping_method' => 'courier',
      'grand_total' => 1600,
      'delivery_price_snapshot' => json_decode($this->snapshotJson(100), true),
    ];
    $transformer = app(OrderEventTransformer::class);
    $data = $transformer->beforeUpdate($order, $event, $transformer->fromEvent($event));
    $order->withoutEvents(fn () => $order->forceFill($data)->save());

    $this->assertEquals([
      'base_price' => 250,
      'ranges' => self::NEW_ZONE_RANGES,
      'checkout_price' => 100,
    ], $this->snapshot($orderId));
  }

  public function test_sub_order_snapshot_is_not_touched(): void
  {
    $this->insertZone($this->newShopId, 250, self::NEW_ZONE_RANGES);
    $parentId = $this->order();
    $childId = $this->insertOrder($this->oldShopId, $this->clientId, [
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'uuid' => $this->orderRow($parentId)->uuid . '-1',
    ]);

    $this->changeShop($childId);

    $this->assertSame($this->newShopId, (int) $this->orderRow($childId)->shop_id);
    $this->assertNull($this->snapshot($childId));
  }
}
