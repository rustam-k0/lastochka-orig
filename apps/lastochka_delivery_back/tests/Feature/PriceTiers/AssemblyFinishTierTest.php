<?php

namespace Tests\Feature\PriceTiers;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\PriceTierSnapshot;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AssemblyFinish (MID-308): по факту сборки в позицию пишутся цена ступени,
 * итог строки, зачёркнутая цена и признак скидки; процентный промокод после
 * этого пересчитывается сам.
 */
class AssemblyFinishTierTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;
  private int $clientId;
  private int $userId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = $this->insertShop();
    $this->clientId = $this->insertClient();
    $this->userId = $this->insertUser();
  }

  /** Штучная позиция со снапшотом 279/249/199 и подтверждённая строка сборки. */
  private function tieredItem(int $orderId, int $assemblyId, int $ordered, float $orderedUnitPrice, int $assembled, float $assembledLineTotal, ?string $snapshot): int
  {
    $isDiscounted = $orderedUnitPrice < 279;

    $orderItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => $ordered,
      'price' => $orderedUnitPrice,
      'price_during_order' => $orderedUnitPrice,
      'price_old' => $isDiscounted ? 279 : null,
      'is_eligible' => $isDiscounted ? 0 : 1,
      'price_tiers' => $snapshot,
    ]);

    $this->insertAssemblyItem($assemblyId, $orderItemId, [
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'quantity' => $assembled,
      'price' => $assembledLineTotal,
    ]);

    return $orderItemId;
  }

  /** Обычная позиция без лесенки на 1000 ₽, собрана как заказана. */
  private function regularItem(int $orderId, int $assemblyId): int
  {
    $orderItemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 1,
      'price' => 1000,
      'price_during_order' => 1000,
    ]);

    $this->insertAssemblyItem($assemblyId, $orderItemId, [
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'quantity' => 1,
      'price' => 1000,
    ]);

    return $orderItemId;
  }

  private function finish(int $assemblyId): void
  {
    AssemblyFinish::assemblyFinish(Assembly::findOrFail($assemblyId), false);
  }

  private function item(int $orderItemId): object
  {
    return \DB::table('order_items')->where('id', $orderItemId)->first();
  }

  private function order(int $orderId): object
  {
    return \DB::table('orders')->where('id', $orderId)->first();
  }

  public function test_five_to_three_rewrites_unit_price_line_total_and_discount_flags(): void
  {
    $this->setPriceTiersFlag(true);
    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $itemId = $this->tieredItem($orderId, $assemblyId, 5, 199, 3, 747, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(3, (int) $item->quantity);
    $this->assertSame(249.0, (float) $item->price_during_order, 'цена за единицу по ступени для 3 шт');
    $this->assertSame(747.0, (float) $item->price, 'итог строки 3 × 249');
    $this->assertSame(279.0, (float) $item->price_old);
    $this->assertSame(0, (int) $item->is_eligible);
    $this->assertSame(Status::ORDER_ITEM_ASSEMBLED, (int) $item->status_id);
    $this->assertSame(747.0, (float) $this->order($orderId)->amount);
  }

  public function test_five_to_one_returns_to_regular_price_and_becomes_eligible(): void
  {
    $this->setPriceTiersFlag(true);
    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $itemId = $this->tieredItem($orderId, $assemblyId, 5, 199, 1, 279, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(279.0, (float) $item->price_during_order);
    $this->assertSame(279.0, (float) $item->price);
    $this->assertNull($item->price_old, 'первая ступень: зачёркнутой цены нет');
    $this->assertSame(1, (int) $item->is_eligible, 'первая ступень: промокод действует');
  }

  public function test_percent_promocode_grows_when_item_becomes_eligible(): void
  {
    $this->setPriceTiersFlag(true);
    // Оформлено: 5 × 199 (со скидкой, вне промокода) + 1000 (eligible), промокод 10% = 100
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1895,
      'items_subtotal' => 1995,
      'discount' => 100,
      'discount_percent' => 10,
      'promocode_code' => 'TIER10',
      'promocode_type' => 'percent',
      'promocode_value' => 10,
      'eligible_amount' => 1000,
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $this->tieredItem($orderId, $assemblyId, 5, 199, 1, 279, $this->snapshotJson(self::$LADDER_FROM_ONE));
    $this->regularItem($orderId, $assemblyId);

    $this->finish($assemblyId);

    $order = $this->order($orderId);
    $this->assertSame(1279.0, (float) $order->items_subtotal);
    $this->assertSame(127.9, (float) $order->discount, '10% от 279 + 1000: позиция вернулась на первую ступень и стала eligible');
    $this->assertSame(1151.1, (float) $order->amount);
  }

  public function test_percent_promocode_shrinks_when_item_reaches_cheaper_tier(): void
  {
    $this->setPriceTiersFlag(true);
    // Оформлено: 1 × 279 (eligible) + 1000 (eligible), промокод 10% = 127.9
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 1151.1,
      'items_subtotal' => 1279,
      'discount' => 127.9,
      'discount_percent' => 10,
      'promocode_code' => 'TIER10',
      'promocode_type' => 'percent',
      'promocode_value' => 10,
      'eligible_amount' => 1279,
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $itemId = $this->tieredItem($orderId, $assemblyId, 1, 279, 5, 995, $this->snapshotJson(self::$LADDER_FROM_ONE));
    $this->regularItem($orderId, $assemblyId);

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(199.0, (float) $item->price_during_order);
    $this->assertSame(279.0, (float) $item->price_old);
    $this->assertSame(0, (int) $item->is_eligible, 'дешёвая ступень: промокод на позицию не действует');

    $order = $this->order($orderId);
    $this->assertSame(100.0, (float) $order->discount, '10% только от обычной позиции');
    $this->assertSame(1895.0, (float) $order->amount, '995 + 1000 − 100');
  }

  public function test_weighted_item_keeps_product_confirm_line_total_and_gets_tier_unit_price(): void
  {
    $this->setPriceTiersFlag(true);
    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 450]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $productId = $this->insertProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    // Заказано 5 порций по 90 (ступень «от 5»), взвешено 880 г → 4 порции → обычные 100
    $itemId = $this->insertOrderItem($orderId, $productId, [
      'quantity' => 5,
      'price' => 90,
      'price_during_order' => 90,
      'price_old' => 100,
      'is_eligible' => 0,
      'price_tiers' => $this->snapshotJson([['min_quantity' => 5, 'price' => 90]], 100.0, null, true),
    ]);
    $this->insertAssemblyItem($assemblyId, $itemId, [
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'quantity' => null,
      'actual_weight' => 880,
      'price' => 440,
    ]);

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(4, (int) $item->quantity);
    $this->assertSame(880, (int) $item->actual_weight);
    $this->assertSame(100.0, (float) $item->price_during_order);
    $this->assertSame(440.0, (float) $item->price, 'итог строки остаётся снапшотом ProductConfirm');
    $this->assertNull($item->price_old);
    $this->assertSame(1, (int) $item->is_eligible);
  }

  /**
   * Позиция «3 шт исходного товара по 150» (со своим снапшотом), собранная заменой.
   *
   * @return array{0:int,1:int,2:int,3:int} [orderId, assemblyId, orderItemId, replacementProductId]
   */
  private function replacedItem(bool $replacementHasLadder, float $assembledLineTotal): array
  {
    $orderId = $this->insertOrder($this->shopId, $this->clientId, [
      'amount' => 450,
      'promocode_code' => 'TIER10',
      'promocode_type' => 'percent',
      'promocode_value' => 10,
      'discount_percent' => 10,
    ]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);

    $originProductId = $this->insertProduct();
    $orderItemId = $this->insertOrderItem($orderId, $originProductId, [
      'quantity' => 3,
      'price' => 150,
      'price_during_order' => 150,
      'price_tiers' => $this->snapshotJson([['min_quantity' => 1, 'price' => 170], ['min_quantity' => 3, 'price' => 150]], 170.0),
    ]);

    $replacementProductId = $this->insertProduct();
    $this->insertPrice($replacementProductId, $this->shopId, 120);
    if ($replacementHasLadder) {
      $this->insertLadder($replacementProductId, $this->shopId, [[1, 120], [3, 100]]);
    }

    $assemblyItemId = $this->insertAssemblyItem($assemblyId, $orderItemId, [
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'type_id' => Type::PRODUCT_NOT_EXISTS,
      'quantity' => 3,
      'price' => $assembledLineTotal,
    ]);

    \DB::table('replacement_products')->insert([
      'product_id' => $replacementProductId,
      'assembly_item_id' => $assemblyItemId,
      'selected' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    return [$orderId, $assemblyId, $orderItemId, $replacementProductId];
  }

  public function test_replacement_gets_fresh_snapshot_of_replacement_product(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $assemblyId, $itemId, $replacementProductId] = $this->replacedItem(true, 300);

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame($replacementProductId, (int) $item->product_id);
    $this->assertNotNull($item->origin_product_id);
    $this->assertSame(100.0, (float) $item->price_during_order, 'ступень «от 3» товара-замены');
    $this->assertSame(300.0, (float) $item->price);
    $this->assertSame(120.0, (float) $item->price_old);
    $this->assertSame(0, (int) $item->is_eligible, 'замена на дешёвой ступени: не eligible, хотя это замена');
    $this->assertEquals([
      'base_price' => 120.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 120.0],
        ['min_quantity' => 3.0, 'price' => 100.0],
      ],
    ], PriceTierSnapshot::fromArray($item->price_tiers)->toArray(), 'снапшот исходного товара заменён снапшотом товара-замены');

    $order = $this->order($orderId);
    $this->assertSame(0.0, (float) $order->discount, 'процентный промокод не действует на замену со скидочной ступенью');
    $this->assertSame(300.0, (float) $order->amount);
  }

  public function test_replacement_without_ladder_clears_origin_snapshot_and_stays_eligible(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $assemblyId, $itemId] = $this->replacedItem(false, 360);

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(120.0, (float) $item->price_during_order);
    $this->assertSame(360.0, (float) $item->price);
    $this->assertNull($item->price_tiers, 'снапшот исходного товара не должен остаться на товаре-замене');
    $this->assertSame(1, (int) $item->is_eligible, 'прежнее правило: замена без лесенки eligible');

    $order = $this->order($orderId);
    $this->assertSame(36.0, (float) $order->discount);
    $this->assertSame(324.0, (float) $order->amount);
  }

  public function test_second_pass_of_collector_payment_gives_the_same_result(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $assemblyId, $replacedItemId] = $this->replacedItem(true, 300);
    $tieredItemId = $this->tieredItem($orderId, $assemblyId, 5, 199, 3, 747, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->finish($assemblyId);
    $afterFirst = [$this->item($replacedItemId), $this->item($tieredItemId), $this->order($orderId)];

    $this->finish($assemblyId);
    $afterSecond = [$this->item($replacedItemId), $this->item($tieredItemId), $this->order($orderId)];

    foreach ([0, 1] as $i) {
      foreach (['quantity', 'price', 'price_during_order', 'price_old', 'is_eligible', 'product_id', 'origin_product_id'] as $field) {
        $this->assertEquals($afterFirst[$i]->{$field}, $afterSecond[$i]->{$field}, "повторный проход изменил {$field}");
      }
      $this->assertEquals(
        json_decode((string) $afterFirst[$i]->price_tiers, true),
        json_decode((string) $afterSecond[$i]->price_tiers, true)
      );
    }

    $this->assertSame(1047.0, (float) $afterSecond[2]->amount, '300 + 747, промокод на обе скидочные позиции не действует');
    $this->assertEquals($afterFirst[2]->discount, $afterSecond[2]->discount);
  }

  public function test_existing_snapshot_is_used_even_when_flag_is_off(): void
  {
    // Позиция со снапшотом, модуль выключен, сборщик подтвердил 3 из 5: цена
    // пересчитана по снапшоту. Флаг гейтит только построение новых снапшотов.
    $this->setPriceTiersFlag(false);
    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    $itemId = $this->tieredItem($orderId, $assemblyId, 5, 199, 3, 747, $this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(249.0, (float) $item->price_during_order);
    $this->assertSame(747.0, (float) $item->price);
    $this->assertSame(279.0, (float) $item->price_old);
    $this->assertSame(0, (int) $item->is_eligible);
    $this->assertSame(747.0, (float) $this->order($orderId)->amount);
  }

  public function test_item_without_snapshot_keeps_old_formulas(): void
  {
    $this->setPriceTiersFlag(true);
    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 995]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);
    // Позиция без лесенки со скидкой по акции: 5 × 199, зачёркнутая 279
    $itemId = $this->tieredItem($orderId, $assemblyId, 5, 199, 3, 597, null);

    $this->finish($assemblyId);

    $item = $this->item($itemId);
    $this->assertSame(199.0, (float) $item->price_during_order);
    $this->assertSame(597.0, (float) $item->price, 'прежняя формула: price_during_order × quantity');
    $this->assertSame(279.0, (float) $item->price_old, 'price_old не трогается');
    $this->assertSame(0, (int) $item->is_eligible, 'is_eligible не трогается');
    $this->assertNull($item->price_tiers);
  }

  public function test_flag_off_replacement_gets_no_fresh_snapshot_and_keeps_old_rule(): void
  {
    // У товара-замены лесенка в магазине есть, но модуль выключен: свежий снапшот
    // не строится (и таблица price_tiers не читается), цена по прежней формуле.
    // Снапшот исходного товара при этом с позиции снимается: иначе возврат у двери
    // посчитал бы товар-замену по чужой лесенке.
    $this->setPriceTiersFlag(false);
    [$orderId, $assemblyId, $itemId] = $this->replacedItem(true, 360);

    \DB::enableQueryLog();
    $this->finish($assemblyId);
    $tierTableReads = collect(\DB::getQueryLog())
      ->filter(fn (array $query) => str_contains($query['query'], 'from `price_tiers`'))
      ->count();
    \DB::disableQueryLog();

    $item = $this->item($itemId);
    $this->assertSame(120.0, (float) $item->price_during_order);
    $this->assertSame(360.0, (float) $item->price);
    $this->assertSame(1, (int) $item->is_eligible, 'прежнее правило: замена eligible');
    $this->assertNull($item->price_tiers, 'снапшот исходного товара снят, свежий не построен');
    $this->assertSame(36.0, (float) $this->order($orderId)->discount);
    $this->assertSame(0, $tierTableReads, 'при выключенном модуле ни одного запроса к таблице price_tiers');
  }

  /**
   * Довесок по итогам ревью задачи 11 (Д1): идемпотентность в продовом виде.
   *
   * В отличие от test_second_pass_of_collector_payment_gives_the_same_result
   * (который зовёт assemblyFinish(false) дважды на СВЕЖЕ загруженной Assembly),
   * здесь ОДИН экземпляр Assembly с уже загруженными связями проходит оба вызова
   * с РАЗНЫМ adjustStock — ровно так, как это делает прод:
   * AssemblyChargePayment::chargeForAssembly вызывает assemblyFinish(false),
   * затем AssemblyFinish::performFinish зовёт assemblyFinish() с adjustStock=true
   * по умолчанию, на том же объекте $assembly, что был загружен один раз в
   * __invoke. Проверяем штучную позицию со снапшотом и замену со свежим
   * снапшотом: origin_product_id не затирается повторно, price_tiers тот же.
   */
  public function test_second_pass_with_production_call_pattern_gives_same_result(): void
  {
    $this->setPriceTiersFlag(true);
    [$orderId, $assemblyId, $replacedItemId] = $this->replacedItem(true, 300);
    $tieredItemId = $this->tieredItem($orderId, $assemblyId, 5, 199, 3, 747, $this->snapshotJson(self::$LADDER_FROM_ONE));

    // Один экземпляр Assembly с загруженными связями, как его грузят __invoke
    // и AssemblyChargePayment::__invoke — оба вызова идут по нему же, а не по
    // свежему Assembly::findOrFail на каждый проход.
    $assembly = Assembly::with(['order', 'assemblyItems.orderItem.supplements'])->findOrFail($assemblyId);

    // Прод: AssemblyChargePayment::chargeForAssembly вызывает с false (без
    // списания остатка), затем performFinish — с true (по умолчанию).
    AssemblyFinish::assemblyFinish($assembly, false);
    $afterFirst = [$this->item($replacedItemId), $this->item($tieredItemId), $this->order($orderId)];

    AssemblyFinish::assemblyFinish($assembly, true);
    $afterSecond = [$this->item($replacedItemId), $this->item($tieredItemId), $this->order($orderId)];

    foreach ([0, 1] as $i) {
      foreach (['quantity', 'price', 'price_during_order', 'price_old', 'is_eligible', 'product_id', 'origin_product_id'] as $field) {
        $this->assertEquals($afterFirst[$i]->{$field}, $afterSecond[$i]->{$field}, "второй проход изменил {$field} у позиции {$i}");
      }
      $this->assertEquals(
        json_decode((string) $afterFirst[$i]->price_tiers, true),
        json_decode((string) $afterSecond[$i]->price_tiers, true)
      );
    }

    $this->assertNotNull($afterSecond[0]->origin_product_id, 'origin_product_id не затёрт вторым проходом');
    $this->assertEquals($afterFirst[2]->amount, $afterSecond[2]->amount);
    $this->assertEquals($afterFirst[2]->discount, $afterSecond[2]->discount);
  }

  /**
   * Довесок по итогам ревью задачи 11 (Д2): весовой товар-замена со своим шагом
   * порции и своей лесенкой. Порции для котировки обязаны считаться по шагу
   * ТОВАРА-ЗАМЕНЫ (250 г), а не исходного товара (200 г).
   */
  public function test_weighted_replacement_with_different_step_and_own_ladder(): void
  {
    $this->setPriceTiersFlag(true);
    $orderId = $this->insertOrder($this->shopId, $this->clientId, ['amount' => 1000]);
    $assemblyId = $this->insertAssembly($orderId, $this->userId);

    // Исходный товар: весовой, шаг 200 г.
    $originProductId = $this->insertProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $orderItemId = $this->insertOrderItem($orderId, $originProductId, [
      'quantity' => 3,
      'price' => 900,
      'price_during_order' => 300,
    ]);

    // Товар-замена: весовой, шаг 250 г (ДРУГОЙ), своя лесенка в БАЗОВЫХ единицах
    // (кг): от 1 кг — 1000 ₽/кг, от 3 кг — 800 ₽/кг.
    $replacementProductId = $this->insertProduct([
      'info_weight' => 250,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $this->insertPrice($replacementProductId, $this->shopId, 300);
    $this->insertLadder($replacementProductId, $this->shopId, [[1, 1000], [3, 800]]);

    $assemblyItemId = $this->insertAssemblyItem($assemblyId, $orderItemId, [
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'type_id' => Type::PRODUCT_NOT_EXISTS,
      'quantity' => null,
      'actual_weight' => 1000,
      'price' => 1000,
    ]);

    \DB::table('replacement_products')->insert([
      'product_id' => $replacementProductId,
      'assembly_item_id' => $assemblyItemId,
      'selected' => 1,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $this->finish($assemblyId);

    $item = $this->item($orderItemId);
    $this->assertSame($replacementProductId, (int) $item->product_id);
    $this->assertSame(1000, (int) $item->actual_weight);
    $this->assertSame(4, (int) $item->quantity, 'порции по шагу товара-замены: 1000 г / 250 г = 4');
    $this->assertSame(250.0, (float) $item->price_during_order, 'ступень «от 4 порций» товара-замены (1 кг = 4 × 250 г)');
    $this->assertSame(1000.0, (float) $item->price, 'итог строки остаётся значением assembly_items.price');
    $this->assertEquals([
      'base_price' => 300.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 4.0, 'price' => 250.0],
        ['min_quantity' => 12.0, 'price' => 200.0],
      ],
    ], PriceTierSnapshot::fromArray($item->price_tiers)->toArray(), 'снапшот переведён в порции по шагу товара-замены, не исходного');
  }
}
