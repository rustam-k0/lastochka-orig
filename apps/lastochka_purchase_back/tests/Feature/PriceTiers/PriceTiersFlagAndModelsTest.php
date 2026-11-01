<?php

namespace Tests\Feature\PriceTiers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Type;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PriceTiersFlagAndModelsTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  public function test_flag_is_disabled_when_setting_is_missing(): void
  {
    DB::table('admin_settings')->where('key', 'project_modules')->delete();

    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_flag_is_enabled_from_project_modules_and_not_memoized(): void
  {
    $this->setPriceTiersFlag(true);
    $this->assertTrue(Order::isPriceTiersModuleEnabled());

    $this->setPriceTiersFlag(false);
    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_broken_blob_means_disabled(): void
  {
    DB::table('admin_settings')->updateOrInsert(
      ['key' => 'project_modules'],
      ['value' => 'не json', 'type' => 'json', 'updated_at' => now()]
    );

    $this->assertFalse(Order::isPriceTiersModuleEnabled());
  }

  public function test_info_weight_constants_match_platform_contract(): void
  {
    // Те же значения, что в admin_back и delivery_back Type::INFO_WEIGHT_*
    $this->assertSame(
      [1, 2, 3, 4, 5],
      [Type::INFO_WEIGHT_PIECE, Type::INFO_WEIGHT_GRAM, Type::INFO_WEIGHT_KILOGRAM, Type::INFO_WEIGHT_LITER, Type::INFO_WEIGHT_M_LITER]
    );
  }

  public function test_price_tier_reads_shared_table_with_float_casts(): void
  {
    $shopId = $this->makeShop('PT model shop');
    $product = $this->makeProduct();
    $this->makeLadder($product->id, $shopId, [[2.5, 249.9]]);

    $tier = PriceTier::query()->where('product_id', $product->id)->where('shop_id', $shopId)->firstOrFail();

    $this->assertSame(2.5, $tier->min_quantity);
    $this->assertSame(249.9, $tier->value);
  }

  public function test_order_item_casts_and_mass_assigns_snapshot_fields(): void
  {
    $shopId = $this->makeShop('PT model shop');
    $order = $this->makeOrder($shopId, $this->makeClient());
    $item = $this->makeOrderItem($order, $this->makeProduct(), [
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE, 279.0, 309.0, false),
    ]);

    $this->assertIsArray($item->price_tiers);
    $this->assertEquals(309.0, $item->price_tiers['base_price_old']);

    $item->update(['price_tiers' => null, 'is_eligible' => false, 'price_old' => 279]);

    $row = DB::table('order_items')->where('id', $item->id)->first();
    $this->assertNull($row->price_tiers);
    $this->assertSame(0, (int) $row->is_eligible);
    $this->assertSame(279.0, (float) $row->price_old);
  }
}
