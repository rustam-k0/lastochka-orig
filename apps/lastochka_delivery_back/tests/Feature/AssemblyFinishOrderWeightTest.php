<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Фиксация суммарного веса заказа при завершении сборки (LASTOCHKI-JAMI-11656).
 *
 * Проверяет полный проход assemblyFinish():
 *  - orders.total_weight = actual_weight весовых + products.weight × qty штучных;
 *  - штучный товар без веса взводит orders.is_weight_partial;
 *  - отменённая при сборке позиция в вес не входит;
 *  - у родителя сплит-заказа вес агрегируется по неотменённым детям.
 */
class AssemblyFinishOrderWeightTest extends TestCase
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
      'title' => 'Weight shop',
      'id_1c' => 999996,
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 1,
      'is_bonus_enabled' => 0,
      'bonus_spend_percent' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function createOrder(?int $parentOrderId = null): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'weight-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => 2,
      'parent_order_id' => $parentOrderId,
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
   * @param array{weight?: ?int, info_weight?: ?int, info_weight_type_id?: ?int} $productAttrs
   */
  private function addItem(
    int $orderId,
    int $assemblyId,
    array $productAttrs,
    int $quantity,
    ?int $actualWeight = null,
    int $assemblyStatusId = Status::PRODUCT_ASSEMBLED
  ): int {
    $productId = \DB::table('products')->insertGetId([
      'title' => 'Weight product ' . uniqid(),
      'articul' => 'W-' . uniqid(),
      'unit_type_id' => 1,
      'weight' => $productAttrs['weight'] ?? null,
      'info_weight' => $productAttrs['info_weight'] ?? null,
      'info_weight_type_id' => $productAttrs['info_weight_type_id'] ?? null,
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
      'actual_weight' => $actualWeight,
      'status_id' => $assemblyStatusId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $productId;
  }

  public function test_assembly_finish_writes_total_weight_and_partial_flag(): void
  {
    $orderId = $this->createOrder();
    $assemblyId = $this->createAssembly($orderId);

    // Весовой товар, взвешен: 700 г
    $this->addItem($orderId, $assemblyId, ['info_weight' => 250, 'info_weight_type_id' => 2], 3, 700);
    // Штучный с весом: 200 г × 2 = 400 г
    $this->addItem($orderId, $assemblyId, ['weight' => 200, 'info_weight_type_id' => 1], 2);
    // Штучный БЕЗ веса → partial
    $this->addItem($orderId, $assemblyId, ['weight' => null, 'info_weight_type_id' => 1], 1);
    // Отменённая при сборке позиция — в вес не входит
    $this->addItem($orderId, $assemblyId, ['weight' => 999, 'info_weight_type_id' => 1], 1, null, Status::PRODUCT_CANCELED);

    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->findOrFail($assemblyId);
    AssemblyFinish::assemblyFinish($assembly);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1100, (int) $order->total_weight);
    $this->assertSame(1, (int) $order->is_weight_partial);
  }

  public function test_assembly_finish_full_weights_no_partial_flag(): void
  {
    $orderId = $this->createOrder();
    $assemblyId = $this->createAssembly($orderId);

    $this->addItem($orderId, $assemblyId, ['info_weight' => 250, 'info_weight_type_id' => 2], 3, 700);
    $this->addItem($orderId, $assemblyId, ['weight' => 200, 'info_weight_type_id' => 1], 2);

    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->findOrFail($assemblyId);
    AssemblyFinish::assemblyFinish($assembly);

    $order = \DB::table('orders')->where('id', $orderId)->first();
    $this->assertSame(1100, (int) $order->total_weight);
    $this->assertSame(0, (int) $order->is_weight_partial);
  }

  public function test_parent_order_aggregates_children_weight(): void
  {
    $parentId = $this->createOrder();

    // Ребёнок 1: штучный 300 г × 2 = 600 г
    $child1Id = $this->createOrder($parentId);
    $assembly1Id = $this->createAssembly($child1Id);
    $product1Id = $this->addItem($child1Id, $assembly1Id, ['weight' => 300, 'info_weight_type_id' => 1], 2);

    // У родителя должен быть item с тем же product_id (syncItemsToParent матчит по нему)
    \DB::table('order_items')->insert([
      'order_id' => $parentId,
      'product_id' => $product1Id,
      'quantity' => 2,
      'price' => 100,
      'price_during_order' => 100,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->findOrFail($assembly1Id);
    AssemblyFinish::assemblyFinish($assembly);

    $child = \DB::table('orders')->where('id', $child1Id)->first();
    $this->assertSame(600, (int) $child->total_weight);

    $parent = \DB::table('orders')->where('id', $parentId)->first();
    $this->assertSame(600, (int) $parent->total_weight);
    $this->assertSame(0, (int) $parent->is_weight_partial);
  }
}
