<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Пересчёт суммы заказа при сборке не теряет копейки (LASTOCHKI-JAMI-11200).
 *
 * Корень бага заказа 58: collectOrderItems() имел return type `int` →
 * накопленная сумма квант-товаров 916.70 обрезалась до 916 → capture в
 * YooKassa списывал 916.00 вместо 916.70 (недосбор у мерчанта).
 */
class AssemblyFinishDecimalTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $assemblyId;

  protected function setUp(): void
  {
    parent::setUp();

    $clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // ignore_stock=1 — collectOrderItems пропускает списание остатков,
    // фикстура без remain'ов; на денежную арифметику это не влияет.
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Decimal shop',
      'id_1c' => 999997,
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

    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'decimal-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Позиция-квант: цена за квант $price, $portions квантов, без взвешивания.
   */
  private function addQuantumItem(float $price, int $portions): void
  {
    $orderId = \DB::table('assemblies')->where('id', $this->assemblyId)->value('order_id');

    $productId = \DB::table('products')->insertGetId([
      'title' => 'Quantum product ' . uniqid(),
      'articul' => 'Q-' . uniqid(),
      'unit_type_id' => 1,
      'info_weight' => 250,
      'info_weight_type_id' => 2, // грамм
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => $portions,
      'price' => $price,
      'price_during_order' => $price,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $this->assemblyId,
      'order_item_id' => $orderItemId,
      'quantity' => $portions,
      'status_id' => \App\Models\Status::PRODUCT_ASSEMBLED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  public function test_collect_order_items_keeps_kopecks(): void
  {
    // Воспроизводим заказ 58: 3 квант-товара, цена кванта дробная
    $this->addQuantumItem(102.75, 2); // 205.50
    $this->addQuantumItem(244.35, 2); // 488.70
    $this->addQuantumItem(111.25, 2); // 222.50  → итого 916.70

    $assembly = Assembly::findOrFail($this->assemblyId);
    $total = AssemblyFinish::collectOrderItems($assembly);

    // До фикта возвращалось 916 (return type int обрезал)
    $this->assertSame(916.70, $total);
  }

  public function test_collect_order_items_single_item_kopecks(): void
  {
    $this->addQuantumItem(102.75, 1); // 102.75

    $assembly = Assembly::findOrFail($this->assemblyId);
    $total = AssemblyFinish::collectOrderItems($assembly);

    $this->assertSame(102.75, $total);

    // order_item тоже хранит дробную сумму позиции
    $oi = \DB::table('order_items')->where('order_id', $assembly->order_id)->first();
    $this->assertSame('102.75', (string) round((float) $oi->price, 2));
  }
}
