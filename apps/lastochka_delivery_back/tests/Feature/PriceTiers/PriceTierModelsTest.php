<?php

namespace Tests\Feature\PriceTiers;

use App\Models\OrderItem;
use App\Models\PriceTier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PriceTierModelsTest extends TestCase
{
  use DatabaseTransactions, PriceTiersFixtures;

  public function test_price_tier_reads_shared_table_with_float_casts(): void
  {
    $shopId = $this->insertShop();
    $productId = $this->insertProduct();
    $this->insertLadder($productId, $shopId, [[2.5, 249.9]]);

    $tier = PriceTier::query()->where('product_id', $productId)->where('shop_id', $shopId)->firstOrFail();

    $this->assertSame(2.5, $tier->min_quantity);
    $this->assertSame(249.9, $tier->value);
  }

  public function test_order_item_casts_price_tiers_to_array(): void
  {
    $shopId = $this->insertShop();
    $orderId = $this->insertOrder($shopId, $this->insertClient());
    $itemId = $this->insertOrderItem($orderId, $this->insertProduct(), [
      'price_tiers' => $this->snapshotJson(self::$LADDER_FROM_ONE, 279.0, 309.0, false),
    ]);

    $item = OrderItem::findOrFail($itemId);

    $this->assertIsArray($item->price_tiers);
    $this->assertEquals(279.0, $item->price_tiers['base_price']);
    $this->assertEquals(309.0, $item->price_tiers['base_price_old']);
    $this->assertFalse($item->price_tiers['base_is_eligible']);
    $this->assertCount(3, $item->price_tiers['tiers']);
  }

  public function test_order_item_without_snapshot_keeps_null(): void
  {
    $shopId = $this->insertShop();
    $orderId = $this->insertOrder($shopId, $this->insertClient());
    $itemId = $this->insertOrderItem($orderId, $this->insertProduct());

    $this->assertNull(OrderItem::findOrFail($itemId)->price_tiers);
  }

  public function test_order_item_mass_assigns_price_old_and_price_tiers(): void
  {
    $shopId = $this->insertShop();
    $orderId = $this->insertOrder($shopId, $this->insertClient());
    $itemId = $this->insertOrderItem($orderId, $this->insertProduct());

    OrderItem::findOrFail($itemId)->update([
      'price_old' => 279,
      'price_tiers' => json_decode($this->snapshotJson(self::$LADDER_FROM_ONE), true),
    ]);

    $row = \DB::table('order_items')->where('id', $itemId)->first();

    $this->assertSame(279.0, (float) $row->price_old);
    $this->assertEquals(279.0, json_decode($row->price_tiers, true)['base_price']);

    OrderItem::findOrFail($itemId)->update(['price_tiers' => null]);

    $this->assertNull(\DB::table('order_items')->where('id', $itemId)->value('price_tiers'));
  }
}
