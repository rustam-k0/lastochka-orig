<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Пересчёт признака «Тяжёлый заказ» (orders.is_heavy) при завершении сборки
 * по ФАКТИЧЕСКОМУ весу против shops.heavy_order_threshold (фича heavy_orders).
 *
 * Проверяет:
 *  - вес > порога → is_heavy=true (тем же update, что total_weight);
 *  - вес упал ниже порога → is_heavy сбрасывается в false;
 *  - модуль выключен → признак не трогается (заморожен как был);
 *  - порог не задан (null) → тяжёлым не помечается;
 *  - OrderEventTransformer::toEvent содержит is_heavy/couriers_count.
 */
class AssemblyFinishHeavyRecalcTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;
  private int $userId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // ignore_stock=1 — collectOrderItems пропускает списание остатков.
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Heavy shop',
      'id_1c' => random_int(900000, 999999),
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 1,
      'is_bonus_enabled' => 0,
      'bonus_spend_percent' => 0,
      'heavy_order_threshold' => null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function enableModule(bool $enabled = true): void
  {
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode(['heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => $enabled]]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
    );
  }

  private function setThreshold(?int $threshold): void
  {
    \DB::table('shops')->where('id', $this->shopId)->update(['heavy_order_threshold' => $threshold]);
  }

  private function createOrder(int $isHeavy = 0): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'heavy-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => 2,
      'is_heavy' => $isHeavy,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function createAssembly(int $orderId): int
  {
    return \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Штучный товар с весом products.weight (граммы). total_weight = weight × quantity.
   */
  private function addPieceItem(int $orderId, int $assemblyId, int $weight, int $quantity): int
  {
    $productId = \DB::table('products')->insertGetId([
      'title' => 'Heavy product ' . uniqid(),
      'articul' => 'H-' . uniqid(),
      'unit_type_id' => 1,
      'weight' => $weight,
      'info_weight' => null,
      'info_weight_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => $quantity,
      'price' => 100,
      'price_during_order' => 100,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => $quantity,
      'actual_weight' => null,
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $productId;
  }

  private function finish(int $assemblyId): void
  {
    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->findOrFail($assemblyId);
    AssemblyFinish::assemblyFinish($assembly);
  }

  /** @test */
  public function assembly_finish_sets_is_heavy_when_actual_weight_exceeds_threshold()
  {
    $this->enableModule();
    $this->setThreshold(10000); // 10 кг

    $orderId = $this->createOrder(0);
    $assemblyId = $this->createAssembly($orderId);
    // штучный weight=6000 г, quantity=2 → 12000 > 10000
    $this->addPieceItem($orderId, $assemblyId, 6000, 2);

    $this->finish($assemblyId);

    $order = Order::find($orderId);
    $this->assertSame(12000, (int) $order->total_weight);
    $this->assertTrue((bool) $order->is_heavy);
  }

  /** @test */
  public function assembly_finish_recalcs_is_heavy_under_courier_assignment_module_only()
  {
    // Platrum 12180: пересчёт признака активен и при courier_assignment
    // (heavy_orders выключен) — совместная доставка входит в модуль
    // «Назначение курьера сборщиком».
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode([
        'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => false],
        'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => true],
      ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
    );
    $this->setThreshold(10000);

    $orderId = $this->createOrder(0);
    $assemblyId = $this->createAssembly($orderId);
    $this->addPieceItem($orderId, $assemblyId, 6000, 2); // 12000 > 10000

    $this->finish($assemblyId);

    $this->assertTrue((bool) Order::find($orderId)->is_heavy);
  }

  /** @test */
  public function calculate_parent_totals_recalcs_is_heavy_under_courier_assignment_module_only()
  {
    // Platrum 12180: пересчёт признака родителя сплит-заказа тоже активен
    // при courier_assignment без heavy_orders (второй гейт-сайт AssemblyFinish)
    \DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => json_encode([
        'heavy_orders' => ['label' => 'Тяжёлые заказы', 'enabled' => false],
        'courier_assignment' => ['label' => 'Назначение курьера сборщиком', 'enabled' => true],
      ]), 'type' => 'json', 'updated_at' => now(), 'created_at' => now()]
    );
    $this->setThreshold(10000);

    $parentId = $this->createOrder(0);
    $childId = $this->createOrder(0);
    \DB::table('orders')->where('id', $childId)->update([
      'parent_order_id' => $parentId,
      'total_weight' => 12000, // > 10000
    ]);

    $method = new \ReflectionMethod(AssemblyFinish::class, 'calculateParentTotals');
    $method->setAccessible(true);
    $updates = $method->invoke(null, Order::findOrFail($parentId));

    $this->assertArrayHasKey('is_heavy', $updates);
    $this->assertTrue((bool) $updates['is_heavy']);
  }

  /** @test */
  public function assembly_finish_clears_is_heavy_when_weight_dropped_below_threshold()
  {
    $this->enableModule();
    $this->setThreshold(10000);

    // заказ создан is_heavy=1 (чекаут-оценка), после отмен вес 8000 < порога 10000
    $orderId = $this->createOrder(1);
    $assemblyId = $this->createAssembly($orderId);
    $this->addPieceItem($orderId, $assemblyId, 4000, 2); // 8000

    $this->finish($assemblyId);

    $this->assertFalse((bool) Order::find($orderId)->is_heavy);
  }

  /** @test */
  public function assembly_finish_does_not_touch_is_heavy_when_module_disabled()
  {
    $this->enableModule(false);
    $this->setThreshold(10000);

    // модуль выключен; заказ is_heavy=1; вес меньше порога
    $orderId = $this->createOrder(1);
    $assemblyId = $this->createAssembly($orderId);
    $this->addPieceItem($orderId, $assemblyId, 4000, 2); // 8000 < 10000

    $this->finish($assemblyId);

    $this->assertTrue((bool) Order::find($orderId)->is_heavy); // не тронут
  }

  /** @test */
  public function assembly_finish_does_not_mark_heavy_when_threshold_not_set()
  {
    $this->enableModule();
    $this->setThreshold(null);

    // heavy_order_threshold = null; вес любой; is_heavy остаётся false
    $orderId = $this->createOrder(0);
    $assemblyId = $this->createAssembly($orderId);
    $this->addPieceItem($orderId, $assemblyId, 6000, 2); // 12000, но порога нет

    $this->finish($assemblyId);

    $this->assertFalse((bool) Order::find($orderId)->is_heavy);
  }

  /** @test */
  public function to_event_contains_is_heavy_and_couriers_count()
  {
    $orderId = $this->createOrder(1);
    \DB::table('orders')->where('id', $orderId)->update([
      'is_heavy' => 1,
      'couriers_count' => 2,
      'amount' => 1500,
      'status_id' => Status::ORDER_IN_DELIVERY,
    ]);

    $order = Order::with('shop')->findOrFail($orderId);

    $event = app(OrderEventTransformer::class)->toEvent($order);

    $this->assertTrue($event['is_heavy']);
    $this->assertSame(2, $event['couriers_count']);
  }
}
