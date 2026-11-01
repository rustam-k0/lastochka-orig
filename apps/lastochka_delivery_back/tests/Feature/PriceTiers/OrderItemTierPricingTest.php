<?php

namespace Tests\Feature\PriceTiers;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderItemTierPricingTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = $this->insertShop();
  }

  private function itemWithSnapshot(?string $snapshot): OrderItem
  {
    $orderId = $this->insertOrder($this->shopId, $this->insertClient());

    return OrderItem::findOrFail($this->insertOrderItem($orderId, $this->insertProduct(), [
      'quantity' => 5,
      'price' => 199,
      'price_during_order' => 199,
      'price_old' => 279,
      'is_eligible' => 0,
      'price_tiers' => $snapshot,
    ]));
  }

  public function test_quote_for_item_with_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $item = $this->itemWithSnapshot($this->snapshotJson(self::$LADDER_FROM_ONE));
    $pricing = new OrderItemTierPricing();

    $three = $pricing->quoteFor($item, 3);
    $this->assertSame(249.0, $three->unitPrice);
    $this->assertSame(279.0, $three->unitPriceOld);
    $this->assertFalse($three->isEligible);

    $one = $pricing->quoteFor($item, 1);
    $this->assertSame(279.0, $one->unitPrice);
    $this->assertNull($one->unitPriceOld);
    $this->assertTrue($one->isEligible);
  }

  public function test_quote_by_existing_snapshot_does_not_depend_on_flag(): void
  {
    // Флаг витрины и флаг курьерки живут в разных БД и могут разойтись: снапшот
    // в позиции означает, что её цена уже посчитана по ступени
    $this->setPriceTiersFlag(false);
    $item = $this->itemWithSnapshot($this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->assertSame(249.0, (new OrderItemTierPricing())->quoteFor($item, 3)->unitPrice);
    $this->assertNotNull((new OrderItemTierPricing())->snapshotOf($item));
  }

  public function test_quote_is_null_for_item_without_snapshot(): void
  {
    $this->setPriceTiersFlag(true);

    $this->assertNull((new OrderItemTierPricing())->quoteFor($this->itemWithSnapshot(null), 3));
  }

  public function test_quote_never_queries_database(): void
  {
    $this->setPriceTiersFlag(true);
    $withSnapshot = $this->itemWithSnapshot($this->snapshotJson(self::$LADDER_FROM_ONE));
    $plain = $this->itemWithSnapshot(null);
    $pricing = new OrderItemTierPricing();

    \DB::enableQueryLog();
    $pricing->quoteFor($withSnapshot, 3);
    $pricing->quoteFor($plain, 3);
    $pricing->deliveredQuote($withSnapshot, null, 5, 2);
    $queries = \DB::getQueryLog();
    \DB::disableQueryLog();

    $this->assertCount(0, $queries, 'котировка по снапшоту позиции не читает ни флаг, ни живые цены');
  }

  public function test_broken_snapshot_means_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $item = $this->itemWithSnapshot(json_encode(['base_price' => 279]));

    $this->assertNull((new OrderItemTierPricing())->quoteFor($item, 3));
    $this->assertNull(OrderItemTierPricing::exposedSnapshot($item));
  }

  public function test_stub_snapshot_from_shop_change_reads_as_no_snapshot(): void
  {
    // Пустышку «снапшот снят» пишет purchase_back при смене магазина заказа:
    // в расчётах это позиция без снапшота, наружу она отдаётся как есть
    $this->setPriceTiersFlag(true);
    $item = $this->itemWithSnapshot($this->snapshotJson([], 380.0));
    $pricing = new OrderItemTierPricing();

    $this->assertNull($pricing->snapshotOf($item));
    $this->assertNull($pricing->quoteFor($item, 3));
    $this->assertNull($pricing->deliveredQuote($item, null, 5, 2));
    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], OrderItemTierPricing::exposedSnapshot($item));
  }

  public function test_flag_is_read_once_per_instance(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct();
    $this->insertLadder($productId, $this->shopId, [[2, 249]]);
    $product = Product::findOrFail($productId);
    $pricing = new OrderItemTierPricing();

    \DB::enableQueryLog();
    $pricing->freshSnapshot($product, $this->shopId, 279.0, null, true);
    $pricing->freshSnapshot($product, $this->shopId, 279.0, null, true);
    $pricing->freshSnapshot($product, $this->shopId, 279.0, null, true);
    $flagReads = collect(\DB::getQueryLog())
      ->filter(fn (array $query) => str_contains($query['query'], 'admin_settings'))
      ->count();
    \DB::disableQueryLog();

    $this->assertSame(1, $flagReads, 'три свежих снапшота = одно чтение флага');
  }

  public function test_delivered_quote_only_for_partial_piece_return(): void
  {
    $this->setPriceTiersFlag(true);
    $item = $this->itemWithSnapshot($this->snapshotJson(self::$LADDER_FROM_ONE));
    $pricing = new OrderItemTierPricing();

    $this->assertSame(249.0, $pricing->deliveredQuote($item, null, 5, 2)->unitPrice, 'доставлено 3 из 5');
    $this->assertNull($pricing->deliveredQuote($item, null, 5, 0), 'возврата нет');
    $this->assertNull($pricing->deliveredQuote($item, null, 5, 5), 'вернули всё');
    $this->assertNull($pricing->deliveredQuote($item, 960, 5, 5), 'весовая возвращается только целиком');
  }

  public function test_fresh_snapshot_for_piece_product(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct();
    $this->insertLadder($productId, $this->shopId, [[5, 199], [1, 279], [2, 249]]);

    $snapshot = (new OrderItemTierPricing())
      ->freshSnapshot(Product::findOrFail($productId), $this->shopId, 279.0, null, true);

    $this->assertEquals([
      'base_price' => 279.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 279.0],
        ['min_quantity' => 2.0, 'price' => 249.0],
        ['min_quantity' => 5.0, 'price' => 199.0],
      ],
    ], $snapshot->toArray());
  }

  public function test_fresh_snapshot_converts_kilogram_ladder_to_quantum_portions(): void
  {
    $this->setPriceTiersFlag(true);
    $productId = $this->insertProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    // В БД лесенка в килограммах: «от 1 кг по 450 ₽ за кг»
    $this->insertLadder($productId, $this->shopId, [[1, 450]]);

    $snapshot = (new OrderItemTierPricing())
      ->freshSnapshot(Product::findOrFail($productId), $this->shopId, 100.0, null, true);

    $this->assertEquals([['min_quantity' => 5.0, 'price' => 90.0]], $snapshot->toArray()['tiers']);
    $this->assertSame(100.0, $snapshot->quote(4)->unitPrice);
    $this->assertSame(90.0, $snapshot->quote(5)->unitPrice);
  }

  public function test_fresh_snapshot_reads_only_ladder_of_order_shop(): void
  {
    $this->setPriceTiersFlag(true);
    $otherShopId = $this->insertShop();
    $productId = $this->insertProduct();
    $this->insertLadder($productId, $otherShopId, [[2, 249]]);

    $pricing = new OrderItemTierPricing();
    $product = Product::findOrFail($productId);

    $this->assertNull($pricing->freshSnapshot($product, $this->shopId, 279.0, null, true), 'лесенка чужого магазина не применяется');
    $this->assertNull($pricing->freshSnapshot($product, null, 279.0, null, true), 'магазин заказа неизвестен');
    $this->assertNotNull($pricing->freshSnapshot($product, $otherShopId, 279.0, null, true));
  }

  public function test_line_price_for_item_with_snapshot(): void
  {
    $this->setPriceTiersFlag(true);
    $item = $this->itemWithSnapshot($this->snapshotJson(self::$LADDER_FROM_ONE));

    $this->assertSame(747.0, (new OrderItemTierPricing())->linePriceFor($item, 3.0), '3 × 249');
  }

  public function test_line_price_for_item_without_snapshot_uses_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    $item = $this->itemWithSnapshot(null);

    $this->assertSame(
      $item->price_during_order * 3,
      (new OrderItemTierPricing())->linePriceFor($item, 3.0),
      'без снапшота — прежняя формула price_during_order × quantity без округления'
    );
  }

  public function test_line_price_for_stub_snapshot_uses_old_formula(): void
  {
    $this->setPriceTiersFlag(true);
    // Пустышка «снапшот снят» (tiers: []) — как позиция без снапшота
    $item = $this->itemWithSnapshot($this->snapshotJson([], 380.0));

    $this->assertSame($item->price_during_order * 3, (new OrderItemTierPricing())->linePriceFor($item, 3.0));
  }

  public function test_line_price_for_never_queries_database(): void
  {
    $this->setPriceTiersFlag(true);
    $withSnapshot = $this->itemWithSnapshot($this->snapshotJson(self::$LADDER_FROM_ONE));
    $plain = $this->itemWithSnapshot(null);
    $pricing = new OrderItemTierPricing();

    \DB::enableQueryLog();
    $pricing->linePriceFor($withSnapshot, 3.0);
    $pricing->linePriceFor($plain, 3.0);
    $queries = \DB::getQueryLog();
    \DB::disableQueryLog();

    $this->assertCount(0, $queries, 'итог строки по снапшоту позиции не читает ни флаг, ни живые цены');
  }

  public function test_flag_off_builds_no_snapshot_and_never_queries_price_tiers_table(): void
  {
    $this->setPriceTiersFlag(false);
    $productId = $this->insertProduct();
    $this->insertLadder($productId, $this->shopId, [[2, 249]]);
    $product = Product::findOrFail($productId);

    \DB::enableQueryLog();
    $snapshot = (new OrderItemTierPricing())->freshSnapshot($product, $this->shopId, 279.0, null, true);
    $tierTableReads = collect(\DB::getQueryLog())
      ->filter(fn (array $query) => str_contains($query['query'], 'from `price_tiers`'))
      ->count();
    \DB::disableQueryLog();

    $this->assertNull($snapshot);
    $this->assertSame(0, $tierTableReads, 'при выключенном модуле ни одного запроса к таблице price_tiers');
  }
}
