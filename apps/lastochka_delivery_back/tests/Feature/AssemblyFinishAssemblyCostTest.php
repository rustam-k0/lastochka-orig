<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Пересчёты суммы заказа не теряют стоимость сборки (orders.assembly_cost,
 * модуль assembly_cost).
 *
 * Баг найден live E2E тестовой YooKassa: холд создавался на total со стоимостью
 * сборки (1995 = 1845 товар + 150 сборка), а после AssemblyFinish amount
 * пересчитывался как items − discount − bonus + delivery_price (без сборки) →
 * capture по grand_total списывал 1845, мерчант недополучал 150.
 */
class AssemblyFinishAssemblyCostTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;

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

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Assembly cost shop',
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
  }

  private function makeOrder(array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'uuid' => 'ac-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /**
   * Заказ с одной собранной позицией: assembly + order_item + assembly_item.
   */
  private function makeAssembledOrder(float $itemPrice, array $orderAttrs = []): int
  {
    $orderId = $this->makeOrder($orderAttrs);

    $userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $productId = \DB::table('products')->insertGetId([
      'title' => 'AC product ' . uniqid(),
      'articul' => 'AC-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => $itemPrice,
      'price_during_order' => $itemPrice,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => 1,
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $assemblyId;
  }

  public function test_assembly_finish_keeps_assembly_cost_in_amount(): void
  {
    // Репро E2E-бага: товар 1845 + сборка 150 → холд 1995, capture должен быть 1995
    $assemblyId = $this->makeAssembledOrder(1845.00, ['assembly_cost' => 150]);

    $assembly = Assembly::findOrFail($assemblyId);
    $order = AssemblyFinish::assemblyFinish($assembly);

    $this->assertSame(1995.00, (float) $order->amount);
  }

  public function test_recalc_order_totals_includes_assembly_cost(): void
  {
    $orderId = $this->makeOrder(['assembly_cost' => 150, 'delivery_price' => 99]);
    $order = Order::findOrFail($orderId);

    $totals = AssemblyFinish::recalcOrderTotals($order, 1000.00);

    // items 1000 + доставка 99 + сборка 150
    $this->assertSame(1249.00, (float) $totals['amount']);
  }

  public function test_absolute_discount_not_scaled_down_by_assembly_cost(): void
  {
    // Абсолютный промокод −100₽: состав сборки не менялся → скидка должна
    // остаться 100. Derivation originalItemsTotal из amount обязан вычитать
    // assembly_cost, иначе знаменатель завышен и скидка режется (клиент переплачивает).
    // amount на момент пересчёта = items 1845 − discount 100 + delivery 0 + сборка 150
    $orderId = $this->makeOrder([
      'amount' => 1895,
      'discount' => 100,
      'assembly_cost' => 150,
    ]);
    $order = Order::findOrFail($orderId);

    $discount = AssemblyFinish::recalculateDiscount($order, 1845.00);

    $this->assertSame(100.00, (float) $discount);
  }

  public function test_percent_discount_eligible_path_not_broken_by_assembly_cost(): void
  {
    // Процентный промокод 10%, is_eligible-данные пришли (все позиции eligible,
    // eligible_amount == сумме товаров). Завышенный derivation ложно уводил в
    // proportional-fallback с заниженной скидкой; корректный путь — сумма
    // eligible-позиций × 10% = 184.5.
    $orderId = $this->makeOrder([
      'amount' => 1895,
      'discount' => 100,
      'discount_percent' => 10,
      'eligible_amount' => 1845,
      'assembly_cost' => 150,
    ]);

    $productId = \DB::table('products')->insertGetId([
      'title' => 'AC eligible product ' . uniqid(),
      'articul' => 'ACE-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('order_items')->insert([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 1845,
      'price_during_order' => 1845,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
      'is_eligible' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $order = Order::findOrFail($orderId);

    $discount = AssemblyFinish::recalculateDiscount($order, 1845.00);

    $this->assertSame(184.50, (float) $discount);
  }

  public function test_calculate_parent_totals_includes_assembly_cost(): void
  {
    $parentId = $this->makeOrder(['assembly_cost' => 150]);
    $this->makeOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'amount' => 1000,
      'status_id' => 2,
    ]);

    $parent = Order::findOrFail($parentId);

    $method = new \ReflectionMethod(AssemblyFinish::class, 'calculateParentTotals');
    $method->setAccessible(true);
    $updates = $method->invoke(null, $parent);

    // children 1000 + сборка родителя 150 (delivery_price = 0)
    $this->assertSame(1150.00, (float) $updates['amount']);
  }
}
