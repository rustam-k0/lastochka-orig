<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Order\OrderPrepare;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Позиция-добавка из номенклатуры (LASTOCHKI-JAMI-11211).
 *
 * Дочерняя позиция order_item (parent_id + source_supplement_value_id) должна:
 * 1. Получать assembly_item при OrderPrepare наравне с корневой позицией.
 * 2. Списывать остаток товара-добавки при AssemblyFinish::collectOrderItems.
 */
class ChildSupplementAssemblyTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;
  private int $collectorId;

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

    // ignore_stock=0 — остатки учитываются при AssemblyFinish
    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Supplement shop',
      'id_1c' => 999991,
      'default' => 0,
      'slot_max_orders' => 10,
      'slot_time' => 60,
      'is_popular' => 0,
      'is_pickup_enabled' => 0,
      'pickup_auto_cancel_enabled' => 0,
      'ignore_stock' => 0,
      'is_bonus_enabled' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->collectorId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Создаёт продукт (без цены/остатка — для тестов создания assembly_item).
   */
  private function insertProduct(string $suffix): int
  {
    return \DB::table('products')->insertGetId([
      'title' => 'Product ' . $suffix,
      'articul' => 'ART-' . $suffix,
      'unit_type_id' => 1,
      'info_weight' => 100,
      'info_weight_type_id' => 2,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Создаёт продукт с остатком в магазине и ценой (для тестов AssemblyFinish).
   */
  private function insertProductWithRemain(string $suffix, int $remainQty = 10): int
  {
    $productId = \DB::table('products')->insertGetId([
      'title' => 'Product ' . $suffix,
      'articul' => 'ART-' . $suffix,
      'unit_type_id' => 1,
      'info_weight' => 100,
      'info_weight_type_id' => 2,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('remains')->insert([
      'product_id' => $productId,
      'shop_id' => $this->shopId,
      'quantity' => $remainQty,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('prices')->insert([
      'product_id' => $productId,
      'value' => 100,
      'step' => 1,
      'type_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $productId;
  }

  /**
   * OrderPrepare создаёт assembly_item для КАЖДОГО order_item заказа —
   * включая дочерние позиции-добавки (parent_id IS NOT NULL).
   */
  public function test_order_prepare_creates_assembly_item_for_child_supplement(): void
  {
    $rootProductId = $this->insertProduct('root');
    $supplementProductId = $this->insertProduct('supplement');

    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'supp-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_CREATED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Корневая позиция
    $rootItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $rootProductId,
      'quantity' => 1,
      'price' => 100,
      'price_during_order' => 100,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Создаём supplement для корневого товара и supplement_value для добавки
    $supplementGroupId = \DB::table('supplements')->insertGetId([
      'title' => 'Test Supplement Group',
      'product_id' => $rootProductId,
      'type' => 'many',
      'is_required' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $supplementValueId = \DB::table('supplement_values')->insertGetId([
      'title' => 'Test Supplement Value',
      'supplement_id' => $supplementGroupId,
      'product_id' => $supplementProductId,
      'price' => 50,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Дочерняя позиция-добавка: parent_id + source_supplement_value_id
    $childItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $supplementProductId,
      'quantity' => 2,
      'price' => 50,
      'price_during_order' => 50,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'parent_id' => $rootItemId,
      'source_supplement_value_id' => $supplementValueId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Запускаем OrderPrepare
    OrderPrepare::orderPrepare(
      Order::with('orderItems')->findOrFail($orderId),
      $this->collectorId
    );

    // Проверяем, что assembly создана
    $assemblyId = \DB::table('assemblies')
      ->where('order_id', $orderId)
      ->value('id');

    $this->assertNotNull($assemblyId, 'Сборка должна быть создана для заказа');

    // assembly_item для КОРНЕВОЙ позиции
    $rootAssemblyItem = \DB::table('assembly_items')
      ->where('assembly_id', $assemblyId)
      ->where('order_item_id', $rootItemId)
      ->first();

    $this->assertNotNull(
      $rootAssemblyItem,
      'assembly_item должен быть создан для корневой позиции'
    );

    // assembly_item для ДОЧЕРНЕЙ позиции-добавки
    $childAssemblyItem = \DB::table('assembly_items')
      ->where('assembly_id', $assemblyId)
      ->where('order_item_id', $childItemId)
      ->first();

    $this->assertNotNull(
      $childAssemblyItem,
      'assembly_item должен быть создан для дочерней позиции-добавки (parent_id IS NOT NULL)'
    );

    $this->assertEquals(
      Status::PRODUCT_WAIT,
      $childAssemblyItem->status_id,
      'Статус assembly_item для добавки должен быть PRODUCT_WAIT'
    );
    $this->assertEquals(
      Type::PRODUCT_WITHOUT_PROBLEMS,
      $childAssemblyItem->type_id,
      'Тип assembly_item для добавки должен быть PRODUCT_WITHOUT_PROBLEMS'
    );
  }

  /**
   * Проверяет relations parent()/childItems() в модели OrderItem.
   */
  public function test_order_item_parent_child_relations(): void
  {
    $productId = $this->insertProduct('rel-root');
    $supplementId = $this->insertProduct('rel-supp');

    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'rel-' . uniqid(),
      'discount' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_CREATED,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $rootItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 100,
      'price_during_order' => 100,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // Создаём supplement + supplement_value для соблюдения FK
    $relSupplementGroupId = \DB::table('supplements')->insertGetId([
      'title' => 'Rel Test Supplement Group',
      'product_id' => $productId,
      'type' => 'one',
      'is_required' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $relSupplementValueId = \DB::table('supplement_values')->insertGetId([
      'title' => 'Rel Test Supplement Value',
      'supplement_id' => $relSupplementGroupId,
      'product_id' => $supplementId,
      'price' => 50,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $childItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $supplementId,
      'quantity' => 1,
      'price' => 50,
      'price_during_order' => 50,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'parent_id' => $rootItemId,
      'source_supplement_value_id' => $relSupplementValueId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $rootItem = \App\Models\OrderItem::findOrFail($rootItemId);
    $childItem = \App\Models\OrderItem::findOrFail($childItemId);

    // childItems relation
    $this->assertCount(1, $rootItem->childItems);
    $this->assertEquals($childItemId, $rootItem->childItems->first()->id);

    // parent relation
    $this->assertNotNull($childItem->parent);
    $this->assertEquals($rootItemId, $childItem->parent->id);

    // source_supplement_value_id доступен через fillable
    $this->assertEquals($relSupplementValueId, $childItem->source_supplement_value_id);
  }
}
