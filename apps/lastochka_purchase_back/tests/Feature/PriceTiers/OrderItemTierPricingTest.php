<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderItemTierPricingTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  private int $shopId;

  protected function setUp(): void
  {
    parent::setUp();

    $this->shopId = $this->makeShop('PT pricing shop');
  }

  public function test_fresh_snapshot_for_piece_product(): void
  {
    $this->setPriceTiersFlag(true);
    $product = $this->makeProduct();
    $this->makeLadder($product->id, $this->shopId, [[5, 300], [1, 380], [3, 350]]);

    $snapshot = (new OrderItemTierPricing())->freshSnapshot($product, $this->shopId, 380.0, null, true);

    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [
        ['min_quantity' => 1.0, 'price' => 380.0],
        ['min_quantity' => 3.0, 'price' => 350.0],
        ['min_quantity' => 5.0, 'price' => 300.0],
      ],
    ], $snapshot->toArray());
  }

  public function test_fresh_snapshot_converts_kilogram_ladder_to_quantum_portions(): void
  {
    $this->setPriceTiersFlag(true);
    $product = $this->makeProduct([
      'info_weight' => 200,
      'info_weight_type_id' => Type::INFO_WEIGHT_GRAM,
      'base_unit_type_id' => Type::INFO_WEIGHT_KILOGRAM,
    ]);
    $this->makeLadder($product->id, $this->shopId, [[1, 450]]);

    $snapshot = (new OrderItemTierPricing())->freshSnapshot($product, $this->shopId, 100.0, null, true);

    $this->assertEquals([['min_quantity' => 5.0, 'price' => 90.0]], $snapshot->toArray()['tiers']);
  }

  public function test_fresh_snapshot_is_null_without_ladder_in_shop(): void
  {
    $this->setPriceTiersFlag(true);
    $otherShopId = $this->makeShop('PT other shop');
    $product = $this->makeProduct();
    $this->makeLadder($product->id, $otherShopId, [[2, 249]]);

    $this->assertNull((new OrderItemTierPricing())->freshSnapshot($product, $this->shopId, 279.0, null, true));
    $this->assertNull((new OrderItemTierPricing())->freshSnapshot($product, null, 279.0, null, true));
    $this->assertNotNull((new OrderItemTierPricing())->freshSnapshot($product, $otherShopId, 279.0, null, true));
  }

  public function test_flag_off_builds_no_snapshot_and_never_queries_price_tiers_table(): void
  {
    $this->setPriceTiersFlag(false);
    $product = $this->makeProduct();
    $this->makeLadder($product->id, $this->shopId, [[2, 249]]);

    DB::enableQueryLog();
    $snapshot = (new OrderItemTierPricing())->freshSnapshot($product, $this->shopId, 279.0, null, true);
    $tierTableReads = collect(DB::getQueryLog())
      ->filter(fn (array $query) => str_contains($query['query'], 'from `price_tiers`'))
      ->count();
    DB::disableQueryLog();

    $this->assertNull($snapshot);
    $this->assertSame(0, $tierTableReads, 'при выключенном модуле ни одного запроса к таблице price_tiers');
  }

  public function test_snapshot_of_item_depends_only_on_stored_snapshot(): void
  {
    $order = $this->makeOrder($this->shopId, $this->makeClient());
    $product = $this->makeProduct();
    $withSnapshot = $this->makeOrderItem($order, $product, ['price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE)]);
    $broken = $this->makeOrderItem($order, $product, ['price_tiers' => json_encode(['base_price' => 279])]);
    $plain = $this->makeOrderItem($order, $product);

    $this->setPriceTiersFlag(true);
    $pricing = new OrderItemTierPricing();
    $this->assertSame(249.0, $pricing->snapshotOf($withSnapshot)->quote(3)->unitPrice);
    $this->assertNull($pricing->snapshotOf($broken));
    $this->assertNull($pricing->snapshotOf($plain));

    // Флаг гейтит только новые снапшоты: уже сохранённый читается всегда
    $this->setPriceTiersFlag(false);
    $this->assertSame(249.0, (new OrderItemTierPricing())->snapshotOf($withSnapshot)->quote(3)->unitPrice);
  }

  public function test_stub_in_column_reads_as_no_snapshot_but_is_exposed_as_is(): void
  {
    $this->setPriceTiersFlag(true);
    $order = $this->makeOrder($this->shopId, $this->makeClient());
    $product = $this->makeProduct();
    $stubbed = $this->makeOrderItem($order, $product, [
      'price_tiers' => json_encode(OrderItemTierPricing::stubSnapshot(380.0)),
    ]);
    $withSnapshot = $this->makeOrderItem($order, $product, ['price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE)]);
    $broken = $this->makeOrderItem($order, $product, ['price_tiers' => json_encode(['base_price' => 279])]);
    $plain = $this->makeOrderItem($order, $product);

    $this->assertNull((new OrderItemTierPricing())->snapshotOf($stubbed), 'пустышка в расчётах = позиция без снапшота');

    $this->assertEquals([
      'base_price' => 380.0,
      'base_price_old' => null,
      'base_is_eligible' => true,
      'tiers' => [],
    ], OrderItemTierPricing::exposedSnapshot($stubbed), 'наружу пустышка отдаётся как есть');
    $this->assertCount(3, OrderItemTierPricing::exposedSnapshot($withSnapshot)['tiers']);
    $this->assertNull(OrderItemTierPricing::exposedSnapshot($broken));
    $this->assertNull(OrderItemTierPricing::exposedSnapshot($plain));
  }
}
