<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Order;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Сплит по зонам сборки (MID-308): пересчитанные по факту цена, зачёркнутая цена,
 * признак скидки и снапшот лесенки дочерней позиции обязаны дойти до родителя,
 * потому что событие заказа строится из позиций родителя.
 */
class SyncItemsToParentTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;
  private int $clientId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
  }

  private function parentRow(int $parentItemId): object
  {
    return \DB::table('order_items')->where('id', $parentItemId)->first();
  }

  private function order(int $orderId): object
  {
    return \DB::table('orders')->where('id', $orderId)->first();
  }

  public function test_recalculated_child_item_is_copied_to_parent_with_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct();
    $snapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);

    $parentId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    // Родитель: как оформлено, 5 × 199
    $parentItemId = $this->insertOrderItem($parentId, $productId, [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0, 'price_tiers' => $snapshot,
    ]);

    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 279, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    // Ребёнок после collectOrderItems: собрана 1 шт по обычной цене
    $this->insertOrderItem($childId, $productId, [
      'quantity' => 1, 'price' => 279, 'price_during_order' => 279,
      'price_old' => null, 'is_eligible' => 1, 'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parent = $this->parentRow($parentItemId);
    $this->assertSame(1, (int) $parent->quantity);
    $this->assertSame(279.0, (float) $parent->price_during_order);
    $this->assertNull($parent->price_old, 'зачёркнутая цена родителя обновлена по факту');
    $this->assertSame(1, (int) $parent->is_eligible);
    $this->assertEquals(json_decode($snapshot, true), json_decode($parent->price_tiers, true));
  }

  public function test_replacement_snapshot_replaces_parent_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $originProductId = $this->insertProduct();
    $replacementProductId = $this->insertProduct();
    $originSnapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);
    $replacementSnapshot = $this->snapshotJson(
      [['min_quantity' => 1, 'price' => 120], ['min_quantity' => 3, 'price' => 100]],
      120.0
    );

    $parentId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 747]);
    $parentItemId = $this->insertOrderItem($parentId, $originProductId, [
      'quantity' => 3, 'price' => 249, 'price_during_order' => 249,
      'price_old' => 279, 'is_eligible' => 0, 'price_tiers' => $originSnapshot,
    ]);

    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 300, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $replacementProductId, [
      'origin_product_id' => $originProductId,
      'quantity' => 3, 'price' => 300, 'price_during_order' => 100,
      'price_old' => 120, 'is_eligible' => 0, 'price_tiers' => $replacementSnapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parent = $this->parentRow($parentItemId);
    $this->assertSame($replacementProductId, (int) $parent->product_id);
    $this->assertSame(120.0, (float) $parent->price_old);
    $this->assertEquals(json_decode($replacementSnapshot, true), json_decode($parent->price_tiers, true));
  }

  public function test_collector_supplement_is_created_on_parent_with_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct();
    $snapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);

    $parentId = $this->insertOrder($this->shopId, $this->clientId);
    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 498, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $productId, [
      'quantity' => 2, 'price' => 498, 'price_during_order' => 249,
      'price_old' => 279, 'is_eligible' => 0, 'is_supplemented' => 1, 'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $created = \DB::table('order_items')->where('order_id', $parentId)->where('product_id', $productId)->first();
    $this->assertNotNull($created, 'добавка сборщика появилась у родителя');
    $this->assertSame(279.0, (float) $created->price_old);
    $this->assertSame(0, (int) $created->is_eligible);
    $this->assertEquals(json_decode($snapshot, true), json_decode($created->price_tiers, true));
  }

  public function test_snapshot_is_copied_even_when_flag_is_off(): void
  {
    $this->setPriceTiersFlag(false);
    $productId = $this->insertProduct();
    $snapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);

    $parentId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    $parentItemId = $this->insertOrderItem($parentId, $productId, [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0, 'price_tiers' => $snapshot,
    ]);

    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 279, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $productId, [
      'quantity' => 1, 'price' => 279, 'price_during_order' => 279,
      'price_old' => null, 'is_eligible' => 1, 'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parent = $this->parentRow($parentItemId);
    $this->assertNull($parent->price_old, 'наследование решает наличие снапшота, а не флаг');
    $this->assertNotNull($parent->price_tiers);
  }

  public function test_items_without_snapshots_are_synced_as_before(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct();

    $parentId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    // Позиция без лесенки со скидкой по акции: зачёркнутая цена живёт на родителе
    $parentItemId = $this->insertOrderItem($parentId, $productId, [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199, 'price_old' => 279, 'is_eligible' => 0,
    ]);

    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 597, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $productId, [
      'quantity' => 3, 'price' => 597, 'price_during_order' => 199, 'price_old' => null, 'is_eligible' => 0,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parent = $this->parentRow($parentItemId);
    $this->assertSame(3, (int) $parent->quantity, 'прежние поля синхронизируются как раньше');
    $this->assertSame(279.0, (float) $parent->price_old, 'без снапшотов price_old не копируется, как и до фичи');
    $this->assertNull($parent->price_tiers);
  }

  /**
   * Деньги: без копирования снапшота родитель считает, что данные о скидочности
   * не пришли (все позиции is_eligible=1, но ни у одной нет price_tiers), и уходит
   * в пропорциональный fallback — 10% от eligible_amount(1000), масштабированного
   * к новому итогу 1279 из «исходных» 1995 = 64.11 вместо честных 127.9 (10% от
   * фактически eligible 279 + 1000). syncItemsToParent обязан скопировать снапшот
   * тиерной позиции родителю ДО того, как calculateParentTotals посчитает скидку.
   */
  public function test_split_order_percent_promocode_uses_synced_snapshot_directly_without_fallback(): void
  {
    $this->setPriceTiersFlag(true);
    $tieredProductId = $this->insertProduct();
    $regularProductId = $this->insertProduct();
    $snapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);

    // Родитель: оформлено 5 × 199 (не eligible, снапшота на родителе ещё нет)
    // + 1000 обычным товаром (eligible). Промокод 10%, eligible_amount = 1000 —
    // по данным сторфронта на момент оформления (тиерная позиция вне промокода).
    $parentId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1895, 'items_subtotal' => 1995, 'discount' => 100, 'discount_percent' => 10,
      'promocode_code' => 'TIER10', 'promocode_type' => 'percent', 'promocode_value' => 10,
      'eligible_amount' => 1000,
    ]);
    $tieredParentItemId = $this->insertOrderItem($parentId, $tieredProductId, [
      'quantity' => 5, 'price' => 995, 'price_during_order' => 199, 'price_old' => 279, 'is_eligible' => 0,
    ]);
    $this->insertOrderItem($parentId, $regularProductId, [
      'quantity' => 1, 'price' => 1000, 'price_during_order' => 1000, 'is_eligible' => 1,
    ]);

    // Ребёнок собран: тиерная позиция вернулась на первую ступень (1 × 279,
    // eligible), обычная — без изменений. Обе позиции одной зоны — один ребёнок.
    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 1279, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $tieredProductId, [
      'quantity' => 1, 'price' => 279, 'price_during_order' => 279,
      'price_old' => null, 'is_eligible' => 1, 'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $regularProductId, [
      'quantity' => 1, 'price' => 1000, 'price_during_order' => 1000, 'is_eligible' => 1,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $tieredParent = $this->parentRow($tieredParentItemId);
    $this->assertEquals(
      json_decode($snapshot, true),
      json_decode($tieredParent->price_tiers, true),
      'без копирования снапшота родитель ушёл бы в пропорциональный fallback'
    );

    $parent = $this->order($parentId);
    $this->assertSame(127.9, (float) $parent->discount, '10% от 279 + 1000 напрямую по eligible-позициям, без fallback (иначе 64.11)');
    $this->assertSame(1151.1, (float) $parent->amount);
  }

  /**
   * Fix round 1, F1: снапшот снимается на родителе, даже когда у самого
   * ребёнка price_tiers уже NULL (замена на товар без лесенки), а у ранее
   * сматченной строки родителя ещё висит снапшот с прошлой синхронизации.
   * Условие «снапшот есть у ребёнка ИЛИ у найденной строки родителя»
   * (AssemblyFinish::syncItemsToParent) обязано сработать по второй половине.
   */
  public function test_replacement_without_ladder_clears_parent_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $originProductId = $this->insertProduct();
    $replacementProductId = $this->insertProduct(); // без лесенки
    $originSnapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);

    $parentId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 747]);
    // У родителя уже висит снапшот исходного товара — от прошлой синхронизации
    $parentItemId = $this->insertOrderItem($parentId, $originProductId, [
      'quantity' => 3, 'price' => 249, 'price_during_order' => 249,
      'price_old' => 279, 'is_eligible' => 0, 'price_tiers' => $originSnapshot,
    ]);

    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 360, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    // Ребёнок заменил на товар без лесенки: снапшот снят (price_tiers/price_old = NULL)
    $this->insertOrderItem($childId, $replacementProductId, [
      'origin_product_id' => $originProductId,
      'quantity' => 3, 'price' => 360, 'price_during_order' => 120,
      'price_old' => null, 'is_eligible' => 1, 'price_tiers' => null,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $parent = $this->parentRow($parentItemId);
    $this->assertSame($replacementProductId, (int) $parent->product_id, 'товар-замена скопирован как обычно');
    $this->assertSame($originProductId, (int) $parent->origin_product_id);
    $this->assertSame(3, (int) $parent->quantity);
    $this->assertSame(360.0, (float) $parent->price);
    $this->assertNull($parent->price_tiers, 'снапшот исходного товара снят вслед за ребёнком');
    $this->assertNull($parent->price_old, 'зачёркнутая цена снята вслед за ребёнком');
  }

  /**
   * Fix round 1, F2: повторный вызов syncItemsToParent (collector_payment
   * прогоняет завершение сборки дважды) не дублирует строку добавки сборщика
   * и даёт ту же цену/снапшот/суммы родителя на втором проходе.
   */
  public function test_repeated_sync_with_snapshot_and_supplement_is_idempotent(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct();
    $supplementProductId = $this->insertProduct();
    $snapshot = $this->snapshotJson(self::$LADDER_FROM_ONE);
    $supplementSnapshot = $this->snapshotJson([['min_quantity' => 1, 'price' => 150]], 150.0);

    $parentId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    $parentItemId = $this->insertOrderItem($parentId, $productId, [
      'quantity' => 5, 'price' => 199, 'price_during_order' => 199,
      'price_old' => 279, 'is_eligible' => 0, 'price_tiers' => $snapshot,
    ]);

    $childId = $this->insertOrder($this->shopId, $this->clientId, [
      'parent_order_id' => $parentId, 'sub_number' => 1, 'amount' => 429, 'status_id' => Status::ORDER_ASSEMBLED,
    ]);
    $this->insertOrderItem($childId, $productId, [
      'quantity' => 1, 'price' => 279, 'price_during_order' => 279,
      'price_old' => null, 'is_eligible' => 1, 'price_tiers' => $snapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);
    // Добавка сборщика со своим свежим снапшотом: строки для неё у родителя ещё нет
    $this->insertOrderItem($childId, $supplementProductId, [
      'quantity' => 1, 'price' => 150, 'price_during_order' => 150,
      'price_old' => null, 'is_eligible' => 1, 'is_supplemented' => 1, 'price_tiers' => $supplementSnapshot,
      'status_id' => Status::ORDER_ITEM_ASSEMBLED,
    ]);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $afterFirstCount = \DB::table('order_items')->where('order_id', $parentId)->count();
    $afterFirstMain = $this->parentRow($parentItemId);
    $afterFirstSupplement = \DB::table('order_items')->where('order_id', $parentId)->where('product_id', $supplementProductId)->first();
    $afterFirstOrder = $this->order($parentId);

    AssemblyFinish::syncItemsToParent(Order::findOrFail($childId));

    $afterSecondCount = \DB::table('order_items')->where('order_id', $parentId)->count();
    $afterSecondMain = $this->parentRow($parentItemId);
    $afterSecondSupplement = \DB::table('order_items')->where('order_id', $parentId)->where('product_id', $supplementProductId)->first();
    $afterSecondOrder = $this->order($parentId);

    $this->assertSame($afterFirstCount, $afterSecondCount, 'повторный вызов не создал дубль строки добавки');
    $this->assertNotNull($afterFirstSupplement, 'добавка сборщика появилась у родителя после первого вызова');
    $this->assertSame($afterFirstSupplement->id, $afterSecondSupplement->id, 'вторая строка добавки не создана');

    $this->assertEquals(json_decode($snapshot, true), json_decode($afterSecondMain->price_tiers, true));
    $this->assertEquals(json_decode($supplementSnapshot, true), json_decode($afterSecondSupplement->price_tiers, true));
    $this->assertSame($afterFirstMain->price_old, $afterSecondMain->price_old);
    $this->assertSame($afterFirstSupplement->price_old, $afterSecondSupplement->price_old);

    $this->assertEquals((float) $afterFirstOrder->amount, (float) $afterSecondOrder->amount);
    $this->assertEquals((float) $afterFirstOrder->discount, (float) $afterSecondOrder->discount);
  }
}
