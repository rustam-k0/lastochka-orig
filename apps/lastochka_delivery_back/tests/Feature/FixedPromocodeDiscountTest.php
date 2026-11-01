<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Скидка по промокоду после сборки: снапшот промокода (тип + номинал) —
 * единый источник правды с витриной.
 *
 * Регрессия прод-заказов 200 и 207 (2026-09-10): промокод ЛАСТОЧКА300 —
 * фиксированные 300 ₽. splitOrderByZones создаёт подзаказы с discount = 0,
 * а discount_percent у fixed-промокода пуст, поэтому recalculateDiscount
 * подзаказа возвращал 0, и calculateParentTotals (discount = сумма детских)
 * затирал родительские 300 ₽. Итог курьерки уходил на 300 ₽ выше витрины,
 * и на эту завышенную сумму пробивался чек.
 *
 * Для фиксированной скидки правило витрины: min(номинал, сумма товаров) —
 * она не масштабируется пропорционально составу.
 */
class FixedPromocodeDiscountTest extends TestCase
{
  use DatabaseTransactions;

  private int $shopId;
  private int $clientId;
  private int $collectorId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = \DB::table('shops')->insertGetId([
      'title' => 'Promocode shop ' . uniqid(),
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

    $this->collectorId = \DB::table('users')->insertGetId([
      'name' => 'Collector',
      'surname' => 'Промокод ' . uniqid(),
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function insertOrder(array $attrs = []): int
  {
    return \DB::table('orders')->insertGetId(array_merge([
      'shop_id' => $this->shopId,
      'client_id' => $this->clientId,
      'uuid' => 'promo-' . uniqid(),
      'discount' => 0,
      'delivery_price' => 0,
      'assembly_cost' => 0,
      'bonus_spent' => 0,
      'payment_type_id' => Type::PAYMENT_CASH,
      'paid' => 0,
      'type_id' => Type::DELIVERY_COURIER,
      'status_id' => Status::ORDER_PREPARING,
      'amount' => 0,
      'created_at' => now(),
      'updated_at' => now(),
    ], $attrs));
  }

  private function insertProduct(): int
  {
    return \DB::table('products')->insertGetId([
      'title' => 'Promo product ' . uniqid(),
      'articul' => 'PRM-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  /**
   * Позиция заказа + строка сборки.
   *
   * @return array{0:int,1:int} [order_item_id, product_id]
   */
  private function insertItem(
    int $orderId,
    ?int $assemblyId,
    float $price,
    int $assemblyStatus = Status::PRODUCT_ASSEMBLED,
    ?int $productId = null
  ): array {
    $productId ??= $this->insertProduct();

    $orderItemId = \DB::table('order_items')->insertGetId([
      'order_id' => $orderId,
      'product_id' => $productId,
      'quantity' => 1,
      'price' => $price,
      'price_during_order' => $price,
      'status_id' => Status::ORDER_ITEM_WAITING,
      'is_eligible' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    if ($assemblyId !== null) {
      \DB::table('assembly_items')->insert([
        'assembly_id' => $assemblyId,
        'order_item_id' => $orderItemId,
        'quantity' => 1,
        'price' => $price,
        'status_id' => $assemblyStatus,
        'created_at' => now(),
        'updated_at' => now(),
      ]);
    }

    return [$orderItemId, $productId];
  }

  private function insertAssembly(int $orderId): int
  {
    return \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $this->collectorId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
  }

  private function orderRow(int $orderId)
  {
    return \DB::table('orders')->where('id', $orderId)->first();
  }

  /** @test */
  public function fixed_promocode_discount_survives_split_by_assembly_zones(): void
  {
    // Родитель: товары 1000, промокод «фикс 300», доставка 100 → к оплате 800.
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'items_subtotal' => 1000,
      'origin_amount' => 700,
      'amount' => 800,
    ]);

    $firstChildId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 600]);
    $secondChildId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 2, 'amount' => 400]);

    $firstAssembly = $this->insertAssembly($firstChildId);
    $secondAssembly = $this->insertAssembly($secondChildId);

    [, $firstProductId] = $this->insertItem($firstChildId, $firstAssembly, 600);
    [, $secondProductId] = $this->insertItem($secondChildId, $secondAssembly, 400);

    // Копии позиций у родителя (splitOrderByZones оставляет оригиналы родителю)
    $this->insertItem($parentId, null, 600, Status::PRODUCT_ASSEMBLED, $firstProductId);
    $this->insertItem($parentId, null, 400, Status::PRODUCT_ASSEMBLED, $secondProductId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($firstAssembly), false);
    AssemblyFinish::assemblyFinish(Assembly::findOrFail($secondAssembly), false);

    $parent = $this->orderRow($parentId);

    $this->assertSame(300.0, (float) $parent->discount, 'Фиксированная скидка родителя не должна теряться при сплите');
    $this->assertSame(800.0, (float) $parent->amount, 'Итог = 1000 товаров − 300 скидки + 100 доставки');
  }

  /** @test */
  public function fixed_promocode_discount_is_capped_by_items_total(): void
  {
    // Осталось товаров на 250 при номинале промокода 300 — скидка не больше суммы товаров.
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'items_subtotal' => 1000,
      'amount' => 800,
    ]);

    $childId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 1000]);
    $assemblyId = $this->insertAssembly($childId);

    [, $keptProductId] = $this->insertItem($childId, $assemblyId, 250);
    [, $cancelledProductId] = $this->insertItem($childId, $assemblyId, 750, Status::PRODUCT_CANCELED);

    $this->insertItem($parentId, null, 250, Status::PRODUCT_ASSEMBLED, $keptProductId);
    $this->insertItem($parentId, null, 750, Status::PRODUCT_ASSEMBLED, $cancelledProductId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    $parent = $this->orderRow($parentId);

    $this->assertSame(250.0, (float) $parent->discount, 'Скидка ограничена суммой собранных товаров');
    $this->assertSame(100.0, (float) $parent->amount, 'Итог = 250 товаров − 250 скидки + 100 доставки');
  }

  /** @test */
  public function fixed_promocode_discount_is_not_scaled_on_single_order(): void
  {
    // Заказ без сплита: часть товаров отменена, но фиксированная скидка
    // остаётся полной (витрина считает min(номинал, сумма товаров)).
    $orderId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'items_subtotal' => 1000,
      'amount' => 800,
    ]);

    $assemblyId = $this->insertAssembly($orderId);
    $this->insertItem($orderId, $assemblyId, 600);
    $this->insertItem($orderId, $assemblyId, 400, Status::PRODUCT_CANCELED);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    $order = $this->orderRow($orderId);

    $this->assertSame(300.0, (float) $order->discount, 'Фиксированная скидка не масштабируется по составу');
    $this->assertSame(400.0, (float) $order->amount, 'Итог = 600 товаров − 300 скидки + 100 доставки');
  }

  /** @test */
  public function percent_promocode_discount_is_counted_once_on_parent(): void
  {
    // Процентный промокод 10%: скидка считается один раз на родителе,
    // подзаказы её не применяют (иначе двойной зачёт).
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 100,
      'discount_percent' => 10,
      'promocode_code' => 'PERCENT10',
      'promocode_type' => 'percent',
      'promocode_value' => 10,
      'items_subtotal' => 1000,
      'amount' => 1000,
    ]);

    $firstChildId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1, 'discount_percent' => 10, 'amount' => 600]);
    $secondChildId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 2, 'discount_percent' => 10, 'amount' => 400]);

    $firstAssembly = $this->insertAssembly($firstChildId);
    $secondAssembly = $this->insertAssembly($secondChildId);

    [, $firstProductId] = $this->insertItem($firstChildId, $firstAssembly, 600);
    [, $secondProductId] = $this->insertItem($secondChildId, $secondAssembly, 400);

    $this->insertItem($parentId, null, 600, Status::PRODUCT_ASSEMBLED, $firstProductId);
    $this->insertItem($parentId, null, 400, Status::PRODUCT_ASSEMBLED, $secondProductId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($firstAssembly), false);
    AssemblyFinish::assemblyFinish(Assembly::findOrFail($secondAssembly), false);

    $parent = $this->orderRow($parentId);

    $this->assertSame(100.0, (float) $parent->discount, '10% от 1000 — ровно один раз');
    $this->assertSame(1000.0, (float) $parent->amount, 'Итог = 1000 товаров − 100 скидки + 100 доставки');
  }

  /** @test */
  public function legacy_order_without_snapshot_keeps_fixed_discount_on_split(): void
  {
    // Заказы, созданные до появления снапшота промокода: тип и номинал
    // не пришли, фиксированная скидка известна только суммой discount.
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'items_subtotal' => 1000,
      'amount' => 800,
    ]);

    $childId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 1000]);
    $assemblyId = $this->insertAssembly($childId);

    [, $productId] = $this->insertItem($childId, $assemblyId, 1000);
    $this->insertItem($parentId, null, 1000, Status::PRODUCT_ASSEMBLED, $productId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    $parent = $this->orderRow($parentId);

    $this->assertSame(300.0, (float) $parent->discount);
    $this->assertSame(800.0, (float) $parent->amount);
  }

  /** @test */
  public function cancelled_sub_order_does_not_reduce_fixed_discount(): void
  {
    // Одна зона отменена целиком: фиксированный номинал остаётся полным,
    // пока сумма оставшихся товаров его покрывает (как на витрине).
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'items_subtotal' => 1000,
      'amount' => 800,
    ]);

    $keptChildId = $this->insertOrder(['parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 600]);
    $cancelledChildId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 2,
      'amount' => 400,
      'status_id' => Status::ORDER_CANCELLED,
    ]);

    $assemblyId = $this->insertAssembly($keptChildId);
    [, $keptProductId] = $this->insertItem($keptChildId, $assemblyId, 600);
    $this->insertItem($cancelledChildId, null, 400);

    $this->insertItem($parentId, null, 600, Status::PRODUCT_ASSEMBLED, $keptProductId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    $parent = $this->orderRow($parentId);

    $this->assertSame(300.0, (float) $parent->discount, 'Номинал не уменьшается из-за отменённой зоны');
    $this->assertSame(400.0, (float) $parent->amount, 'Итог = 600 товаров − 300 скидки + 100 доставки');
  }

  /** @test */
  public function sub_order_does_not_apply_promocode_itself(): void
  {
    // У подзаказа снапшот промокода скопирован из родителя, но применять
    // его он не должен — иначе скидка засчитается дважды.
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'items_subtotal' => 1000,
      'amount' => 800,
    ]);

    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'amount' => 1000,
    ]);

    $assemblyId = $this->insertAssembly($childId);
    [, $productId] = $this->insertItem($childId, $assemblyId, 1000);
    $this->insertItem($parentId, null, 1000, Status::PRODUCT_ASSEMBLED, $productId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    $child = $this->orderRow($childId);
    $parent = $this->orderRow($parentId);

    $this->assertSame(0.0, (float) $child->discount, 'Скидка живёт на родителе');
    $this->assertSame(1000.0, (float) $child->amount, 'Сумма подзаказа — стоимость его товаров');
    $this->assertSame(300.0, (float) $parent->discount, 'Скидка засчитана один раз');
    $this->assertSame(800.0, (float) $parent->amount);
  }

  /** @test */
  public function items_subtotal_reflects_assembled_composition(): void
  {
    // Шапка заказа в админке считает «Стоимость товаров − Скидка + Доставка = Итого».
    // Пока items_subtotal оставался исходным, арифметика не сходилась на сумму
    // отменённых позиций (жалоба по прод-заказу 200).
    $parentId = $this->insertOrder([
      'delivery_price' => 100,
      'discount' => 300,
      'promocode_code' => 'ЛАСТОЧКА300',
      'promocode_type' => 'fixed',
      'promocode_value' => 300,
      'items_subtotal' => 1000,
      'amount' => 800,
    ]);

    $childId = $this->insertOrder([
      'parent_order_id' => $parentId,
      'sub_number' => 1,
      'items_subtotal' => 1000,
      'amount' => 1000,
    ]);
    $assemblyId = $this->insertAssembly($childId);

    [, $keptProductId] = $this->insertItem($childId, $assemblyId, 600);
    [, $cancelledProductId] = $this->insertItem($childId, $assemblyId, 400, Status::PRODUCT_CANCELED);

    $this->insertItem($parentId, null, 600, Status::PRODUCT_ASSEMBLED, $keptProductId);
    $this->insertItem($parentId, null, 400, Status::PRODUCT_ASSEMBLED, $cancelledProductId);

    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);

    $this->assertSame(600.0, (float) $this->orderRow($childId)->items_subtotal, 'Подзаказ: сумма собранных товаров');
    $this->assertSame(600.0, (float) $this->orderRow($parentId)->items_subtotal, 'Родитель: сумма товаров подзаказов');
  }
}
