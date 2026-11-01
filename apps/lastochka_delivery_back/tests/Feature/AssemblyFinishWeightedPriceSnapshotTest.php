<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\OrderItem;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AssemblyFinish переносит в order_items.price итог весовой строки, посчитанный
 * ProductConfirm (assembly_items.price), а НЕ пересчитывает его по текущему
 * product.info_weight: правка карточки товара между подтверждением сборщиком и
 * завершением сборки меняла сумму заказа (e2e dev 2026-08-28: конфеты 225.12 →
 * 112.56 после смены шага 250 → 500 г).
 */
class AssemblyFinishWeightedPriceSnapshotTest extends TestCase
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
      'title' => 'Snapshot shop',
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
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /** @test */
  public function assembly_finish_keeps_product_confirm_price_when_product_step_changed()
  {
    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'snap-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $productId = \DB::table('products')->insertGetId([
      'title' => 'Snapshot product ' . uniqid(),
      'articul' => 'S-' . uniqid(),
      'unit_type_id' => 1,
      'info_weight' => 250,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 3,
      'price' => 105,
      'price_during_order' => 105,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    // ProductConfirm: 536 г при шаге 250 г → 105 × 536 / 250 = 225.12
    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => null,
      'actual_weight' => 536,
      'price' => 225.12,
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Карточку товара поменяли между подтверждением и завершением сборки
    \DB::table('products')->where('id', $productId)->update(['info_weight' => 500]);

    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->findOrFail($assemblyId);
    AssemblyFinish::assemblyFinish($assembly);

    $item = OrderItem::findOrFail($orderItemId);
    $this->assertSame(225.12, (float) $item->price);
    $this->assertSame(105.0, (float) $item->price_during_order);
    $this->assertSame(536, (int) $item->actual_weight);
  }
}
