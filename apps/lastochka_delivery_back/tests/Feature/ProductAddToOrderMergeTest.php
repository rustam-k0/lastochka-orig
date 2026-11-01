<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Повторное «Добавить товар» во время сборки не должно плодить дубли позиций.
 *
 * Прод-заказ 208-1 (10.09.2026): сборщик трижды добавил один и тот же товар
 * замены, получил три отдельные строки order_items по 1 шт и раздутый
 * orders.amount (2 796,40 → 3 445,40 ₽). Дубли ломают и синк в родительский
 * заказ: syncItemsToParent матчит строки один к одному (прод-заказы 386, 370).
 *
 * Правило: добавление сливается только с позицией, которую сборщик уже добавил
 * в ЭТОЙ сборке и которая ещё ждёт сборки. Клиентские позиции заказа, уже
 * собранные и отменённые добавки остаются нетронутыми.
 */
class ProductAddToOrderMergeTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  private const M_PRODUCT_ADD = '
    mutation ProductAddToOrder($assembly_id: Int!, $product_id: Int!, $quantity: Int!) {
      ProductAddToOrder(assembly_id: $assembly_id, product_id: $product_id, quantity: $quantity) { code message }
    }
  ';

  private int $shopId;
  private int $clientId;
  private User $collector;

  protected function setUp(): void
  {
    parent::setUp();

    $this->mock(FcmNotificationService::class)
      ->shouldReceive('sendToUser')->andReturnNull()
      ->shouldReceive('sendToUsers')->andReturnNull();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Merge shop ' . uniqid(),
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

    $this->clientId = \DB::table('clients')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->collector = $this->makeCollector();
  }

  private function makeCollector(): User
  {
    // User в этом репо полностью guarded — сырой insert + findOrFail.
    $userId = \DB::table('users')->insertGetId([
      'name' => 'Collector', 'surname' => 'Мерж ' . uniqid(),
      'password' => 'test',
      'created_at' => now(), 'updated_at' => now(),
    ]);

    $user = User::findOrFail($userId);
    $user->assignRole(Role::findOrCreate('collector', 'api'));

    \DB::table('shifts')->insert([
      'user_id' => $user->id, 'shop_id' => $this->shopId,
      'created_at' => now(), 'updated_at' => now(),
    ]);

    return $user;
  }

  private function insertOrder(array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'client_id' => $this->clientId,
      'uuid' => 'merge-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_CASH,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 1000.0,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /**
   * Товар с ценой в магазине заказа: ProductAddToOrder читает prices по shop_id.
   */
  private function insertProduct(float $price = 649.0): int
  {
    $productId = \DB::table('products')->insertGetId([
      'title' => 'Merge product ' . uniqid(),
      'articul' => 'MRG-' . uniqid(),
      'shop_id' => $this->shopId,
      'unit_type_id' => Type::UNIT_PIECE,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('prices')->insert([
      'product_id' => $productId,
      'shop_id' => $this->shopId,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => $price,
      'step' => 1,
      'blocked' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('remains')->insert([
      'product_id' => $productId,
      'shop_id' => $this->shopId,
      'quantity' => 100,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return $productId;
  }

  /**
   * Сборка с одной клиентской позицией, чтобы заказ не был пустым.
   *
   * @return array{order_id:int, assembly_id:int}
   */
  private function insertAssembly(?int $orderId = null): array
  {
    $orderId ??= $this->insertOrder();

    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $this->insertProduct(1000.0),
      'quantity' => 1,
      'price' => 1000.0,
      'price_during_order' => 1000.0,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $assemblyId,
      'order_item_id' => $orderItemId,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return ['order_id' => $orderId, 'assembly_id' => $assemblyId];
  }

  private function addProduct(int $assemblyId, int $productId, int $quantity = 1)
  {
    $this->actingAs($this->collector, 'api');

    return $this->graphQL(self::M_PRODUCT_ADD, [
      'assembly_id' => $assemblyId,
      'product_id' => $productId,
      'quantity' => $quantity,
    ]);
  }

  private function itemsOf(int $orderId, int $productId): \Illuminate\Support\Collection
  {
    return \DB::table('order_items')
      ->where('order_id', $orderId)
      ->where('product_id', $productId)
      ->whereNull('deleted_at')
      ->orderBy('id')
      ->get();
  }

  /** @test */
  public function repeated_add_of_same_product_increases_quantity_of_existing_item(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $this->addProduct($ids['assembly_id'], $productId);
    $response = $this->addProduct($ids['assembly_id'], $productId);

    $this->assertSame(200, $response->json('data.ProductAddToOrder.code'));

    $items = $this->itemsOf($ids['order_id'], $productId);

    $this->assertCount(1, $items, 'Повторное добавление создало дубль позиции');
    $this->assertSame(2, (int) $items->first()->quantity);
    $this->assertEqualsWithDelta(1298.0, (float) $items->first()->price, 0.01);
  }

  /** @test */
  public function repeated_add_of_same_product_does_not_create_second_assembly_item(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $this->addProduct($ids['assembly_id'], $productId);
    $this->addProduct($ids['assembly_id'], $productId);

    $assemblyItems = \DB::table('assembly_items')
      ->join('order_items', 'order_items.id', '=', 'assembly_items.order_item_id')
      ->where('assembly_items.assembly_id', $ids['assembly_id'])
      ->where('order_items.product_id', $productId)
      ->whereNull('assembly_items.deleted_at')
      ->count();

    $this->assertSame(1, $assemblyItems, 'Повторное добавление создало вторую позицию сборки');
  }

  /** @test */
  public function repeated_add_increases_order_amount_once_per_unit(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $amountBefore = (float) \DB::table('orders')->where('id', $ids['order_id'])->value('amount');

    $this->addProduct($ids['assembly_id'], $productId);
    $this->addProduct($ids['assembly_id'], $productId);

    $amountAfter = (float) \DB::table('orders')->where('id', $ids['order_id'])->value('amount');

    $this->assertEqualsWithDelta(1298.0, $amountAfter - $amountBefore, 0.01);
  }

  /** @test */
  public function add_does_not_merge_into_item_ordered_by_client(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    // Клиент заказал этот же товар — позиция заказа, не добавка сборщика.
    $clientItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $ids['order_id'],
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 649.0,
      'price_during_order' => 649.0,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'is_supplemented' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $ids['assembly_id'],
      'order_item_id' => $clientItemId,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->addProduct($ids['assembly_id'], $productId);

    $items = $this->itemsOf($ids['order_id'], $productId);

    $this->assertCount(2, $items, 'Добавка слилась с позицией, которую заказал клиент');
    $this->assertSame(1, (int) $items->firstWhere('id', $clientItemId)->quantity);
  }

  /**
   * @test
   *
   * Ловушка перегруженного флага: в admin_back тот же order_items.is_supplemented
   * означает «у позиции есть добавки из витрины» (OrderEventTransformer пишет
   * `is_supplemented => !empty($itemData['supplements'])`), а в курьерке —
   * «позицию добавил сборщик». Кофе с сиропом приезжает из витрины с единицей
   * в этом флаге, и слить в него добавку сборщика нельзя: количество добавок
   * считается от количества позиции.
   */
  public function add_does_not_merge_into_client_item_that_only_has_supplements(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $clientItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $ids['order_id'],
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 649.0,
      'price_during_order' => 649.0,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'is_supplemented' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $supplementId = \DB::table('supplements')->insertGetId([
      'title' => 'Сироп ' . uniqid(),
      'product_id' => $productId,
      'type' => 'one',
      'is_required' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('order_item_supplements')->insert([
      'order_item_id' => $clientItemId,
      'supplement_id' => $supplementId,
      'amount' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    \DB::table('assembly_items')->insert([
      'assembly_id' => $ids['assembly_id'],
      'order_item_id' => $clientItemId,
      'status_id' => Status::PRODUCT_WAIT,
      'type_id' => Type::PRODUCT_WITHOUT_PROBLEMS,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->addProduct($ids['assembly_id'], $productId);

    $items = $this->itemsOf($ids['order_id'], $productId);

    $this->assertCount(2, $items, 'Добавка слилась с клиентской позицией, у которой есть добавки');
    $this->assertSame(1, (int) $items->firstWhere('id', $clientItemId)->quantity);
  }

  /** @test */
  public function add_creates_new_item_when_previous_supplement_already_assembled(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $this->addProduct($ids['assembly_id'], $productId);

    $addedItemId = (int) $this->itemsOf($ids['order_id'], $productId)->first()->id;

    // Сборщик уже подтвердил добавку: количество зафиксировано.
    \DB::table('assembly_items')
      ->where('order_item_id', $addedItemId)
      ->update(['status_id' => Status::PRODUCT_ASSEMBLED, 'quantity' => 1, 'updated_at' => now()]);

    $this->addProduct($ids['assembly_id'], $productId);

    $items = $this->itemsOf($ids['order_id'], $productId);

    $this->assertCount(2, $items, 'Добавка слилась с уже собранной позицией');
    $this->assertSame(1, (int) $items->firstWhere('id', $addedItemId)->quantity);
  }

  /** @test */
  public function add_creates_new_item_when_previous_supplement_was_canceled(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $this->addProduct($ids['assembly_id'], $productId);

    $addedItemId = (int) $this->itemsOf($ids['order_id'], $productId)->first()->id;

    \DB::table('assembly_items')
      ->where('order_item_id', $addedItemId)
      ->update(['status_id' => Status::PRODUCT_CANCELED, 'updated_at' => now()]);

    $this->addProduct($ids['assembly_id'], $productId);

    $items = $this->itemsOf($ids['order_id'], $productId);

    $this->assertCount(2, $items, 'Добавка слилась с отменённой позицией');
  }

  /** @test */
  public function add_creates_new_item_when_previous_supplement_belongs_to_another_assembly(): void
  {
    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $this->addProduct($ids['assembly_id'], $productId);

    // Пересборка заказа: прежняя сборка закрыта, началась новая.
    \DB::table('assemblies')->where('id', $ids['assembly_id'])->update(['finished_at' => now()]);

    $secondAssemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $ids['order_id'],
      'user_id' => $this->collector->id,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->addProduct($secondAssemblyId, $productId);

    $items = $this->itemsOf($ids['order_id'], $productId);

    $this->assertCount(2, $items, 'Добавка слилась с позицией чужой сборки');
  }

  /** @test */
  public function merged_add_leaves_stock_to_assembly_finish(): void
  {
    // Магазин с учётом остатков. Добавление остаток не списывает: позицию
    // спишет AssemblyFinish по собранному (раньше списывалось дважды —
    // здесь и при завершении сборки, см. StockUnitsMovementTest).
    \DB::table('shops')->where('id', $this->shopId)->update(['ignore_stock' => 0]);

    $ids = $this->insertAssembly();
    $productId = $this->insertProduct(649.0);

    $this->addProduct($ids['assembly_id'], $productId, 2);
    $this->addProduct($ids['assembly_id'], $productId, 3);

    $remain = (float) \DB::table('remains')
      ->where('product_id', $productId)
      ->where('shop_id', $this->shopId)
      ->value('quantity');

    $this->assertEqualsWithDelta(100.0, $remain, 0.001);
  }
}
