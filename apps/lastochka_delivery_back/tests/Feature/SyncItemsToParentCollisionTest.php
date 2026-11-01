<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Order;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Склейка позиций подзаказа в родительский заказ (AssemblyFinish::syncItemsToParent).
 *
 * Родитель сплит-заказа — единственный источник состава для события order.updated
 * (его сериализует OrderEventTransformer), поэтому его позиции обязаны повторять
 * состав детей. Иначе витрина получает событие, где сумма позиций не сходится с
 * grand_total, теряет позиции у клиента, а фискальный чек уходит с растянутыми
 * ценами (прод-заказы 386, 370, 133).
 */
class SyncItemsToParentCollisionTest extends TestCase
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
      'title' => 'Split sync shop',
      'id_1c' => 999994,
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

  private function createOrder(?int $parentOrderId = null, float $amount = 0): int
  {
    return \DB::table('orders')->insertGetId([
      'shop_id' => $this->shopId,
      'uuid' => 'split-sync-' . uniqid(),
      'amount' => $amount,
      'discount' => 0,
      'delivery_price' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => 1,
      'paid' => 0,
      'type_id' => 2,
      'client_id' => $this->clientId,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
      'parent_order_id' => $parentOrderId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function createProduct(string $suffix): int
  {
    return \DB::table('products')->insertGetId([
      'title' => 'Split product ' . $suffix . ' ' . uniqid(),
      'articul' => 'SP-' . $suffix . '-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function addOrderItem(int $orderId, int $productId, array $attrs = []): int
  {
    return \DB::table('order_items')->insertGetId(array_merge([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => 100,
      'price_during_order' => 100,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
      'is_supplemented' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  /**
   * Прод-заказ 386: сборщик добавил товар-замену отдельной позицией
   * (ProductAddToOrder), потом её отменил, а оригинал подтвердил как замену на
   * тот же товар. У родителя обе дочерние строки указывали на одну строку —
   * отменённая шла второй и затирала замену, унося её цену и origin_product_id.
   */
  public function test_cancelled_supplement_does_not_overwrite_replacement_on_parent(): void
  {
    $originProductId = $this->createProduct('origin');
    $replacementProductId = $this->createProduct('replacement');

    $parentId = $this->createOrder();
    $parentItemId = $this->addOrderItem($parentId, $originProductId, ['price' => 140.40]);

    $childId = $this->createOrder($parentId, 71.60);
    // Замена: оригинал переделан на товар-замену, взвешен, 71.60
    $this->addOrderItem($childId, $replacementProductId, [
      'origin_product_id' => $originProductId,
      'actual_weight' => 102,
      'price' => 71.60,
      'price_during_order' => 140.40,
    ]);
    // Та же номенклатура, добавлена сборщиком и отменена — денег не несёт
    $this->addOrderItem($childId, $replacementProductId, [
      'is_supplemented' => 1,
      'status_id' => Status::ORDER_ITEM_CANCELED,
      'price' => 140.40,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parentItem = \DB::table('order_items')->where('id', $parentItemId)->first();
    $this->assertSame($replacementProductId, (int) $parentItem->product_id, 'товар замены сохранён');
    $this->assertSame($originProductId, (int) $parentItem->origin_product_id, 'origin замены не потерян');
    $this->assertSame(Status::ORDER_ITEM_ASSEMBLED, (int) $parentItem->status_id, 'замена не отменена');
    $this->assertEqualsWithDelta(71.60, (float) $parentItem->price, 0.001, 'цена замены сохранена');
  }

  /**
   * Прод-заказ 133: сборщик добавил вторую позицию той же номенклатуры
   * (не отменённую). Деньги за неё попадают в parent.amount через
   * calculateParentTotals, значит и строка у родителя обязана появиться —
   * иначе состав родителя не сходится с его же суммой.
   */
  public function test_collector_supplement_creates_its_own_parent_item(): void
  {
    $productId = $this->createProduct('banana');

    $parentId = $this->createOrder();
    $this->addOrderItem($parentId, $productId, ['price' => 80.00]);

    $childId = $this->createOrder($parentId, 115.71);
    $this->addOrderItem($childId, $productId, ['price' => 80.00]);
    $this->addOrderItem($childId, $productId, ['is_supplemented' => 1, 'price' => 35.71]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parentItems = \DB::table('order_items')
      ->where('order_id', $parentId)
      ->where('status_id', '<>', Status::ORDER_ITEM_CANCELED)
      ->get();

    $this->assertCount(2, $parentItems, 'добавленная сборщиком позиция появилась у родителя');
    $this->assertEqualsWithDelta(115.71, (float) $parentItems->sum('price'), 0.001,
      'состав родителя сходится с суммой заказа');
  }

  /**
   * Прод-заказы 436 и 459 (11.09.2026): при включённом модуле collector_payment
   * завершение сборки прогоняет assemblyFinish ДВАЖДЫ — сначала
   * AssemblyChargePayment::chargeForAssembly (списание оплаты, без списания
   * остатка), затем AssemblyFinish::performFinish. Оба прохода зовут
   * syncItemsToParent, поэтому склейка обязана быть идемпотентной.
   *
   * Матчинг шёл по ЖИВОМУ product_id родителя: после первого прохода строка
   * родителя уже несёт товар-замену, и второй проход не находил её по
   * origin_product_id ребёнка — создавалась строка-дубль. Заказ 436 получил
   * три лишние позиции (+495.96), заказ 459 — одну (+44.90); витрина показала
   * клиенту завышенный состав и сумму.
   */
  public function test_repeated_sync_does_not_duplicate_replacement_on_parent(): void
  {
    $originProductId = $this->createProduct('origin-repeat');
    $replacementProductId = $this->createProduct('replacement-repeat');

    $parentId = $this->createOrder();
    $parentItemId = $this->addOrderItem($parentId, $originProductId, ['price' => 45.00]);

    $childId = $this->createOrder($parentId, 44.90);
    $this->addOrderItem($childId, $replacementProductId, [
      'origin_product_id' => $originProductId,
      'origin_price' => 45.00,
      'origin_quantity' => 1,
      'price' => 44.90,
      'price_during_order' => 45.00,
    ]);

    $child = Order::findOrFail($childId);
    AssemblyFinish::syncItemsToParent($child);
    AssemblyFinish::syncItemsToParent($child->fresh('orderItems'));

    $parentItems = \DB::table('order_items')->where('order_id', $parentId)->get();

    $this->assertCount(1, $parentItems, 'повторная склейка не плодит строку-дубль');
    $this->assertSame($parentItemId, (int) $parentItems->first()->id, 'замена осталась в исходной строке');
    $this->assertSame($replacementProductId, (int) $parentItems->first()->product_id, 'товар замены сохранён');
    $this->assertSame($originProductId, (int) $parentItems->first()->origin_product_id, 'origin замены сохранён');
    $this->assertEqualsWithDelta(44.90, (float) $parentItems->first()->price, 0.001, 'цена замены сохранена');
  }

  /**
   * Прод-заказ 459: замена (клубника → вишня) плюс вторая позиция того же
   * товара-замены, добавленная сборщиком. Первый проход создаёт строку под
   * добавку, второй обязан найти обе строки, а не создать третью.
   */
  public function test_repeated_sync_keeps_replacement_and_supplement_separate(): void
  {
    $originProductId = $this->createProduct('origin-mix');
    $replacementProductId = $this->createProduct('replacement-mix');

    $parentId = $this->createOrder();
    $this->addOrderItem($parentId, $originProductId, ['price' => 45.00]);

    $childId = $this->createOrder($parentId, 89.80);
    $this->addOrderItem($childId, $replacementProductId, [
      'origin_product_id' => $originProductId,
      'origin_price' => 45.00,
      'origin_quantity' => 1,
      'price' => 44.90,
      'price_during_order' => 45.00,
    ]);
    $this->addOrderItem($childId, $replacementProductId, [
      'is_supplemented' => 1,
      'price' => 44.90,
    ]);

    $child = Order::findOrFail($childId);
    AssemblyFinish::syncItemsToParent($child);
    AssemblyFinish::syncItemsToParent($child->fresh('orderItems'));

    $parentItems = \DB::table('order_items')->where('order_id', $parentId)->get();

    $this->assertCount(2, $parentItems, 'у родителя ровно замена и добавка сборщика');
    $this->assertEqualsWithDelta(89.80, (float) $parentItems->sum('price'), 0.001,
      'состав родителя сходится с суммой подзаказа');
    $this->assertSame(1, $parentItems->where('origin_product_id', $originProductId)->count(),
      'origin замены записан ровно у одной строки');
  }
}
