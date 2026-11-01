<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\Store;
use App\Services\Pricing\PriceTierLadder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductPriceTierModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_created(): void
    {
        $this->assertTrue(Schema::hasTable('product_price_tiers'));
        $this->assertTrue(Schema::hasColumns('product_price_tiers', [
            'id', 'product_id', 'store_id', 'min_quantity', 'price', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('order_items', 'price_tiers'));
    }

    public function test_threshold_is_unique_per_product_and_store(): void
    {
        $product = Product::factory()->create();

        ProductPriceTier::create([
            'product_id' => $product->id, 'store_id' => $product->store_id,
            'min_quantity' => 2, 'price' => 249,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ProductPriceTier::create([
            'product_id' => $product->id, 'store_id' => $product->store_id,
            'min_quantity' => 2, 'price' => 240,
        ]);
    }

    public function test_replace_ladder_is_full_replacement_scoped_to_store(): void
    {
        $product = Product::factory()->create();
        $otherStore = Store::factory()->create();

        ProductPriceTier::replaceLadder($product->id, (int) $product->store_id, PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 249],
            ['min_quantity' => 5, 'price' => 199],
        ]));
        ProductPriceTier::replaceLadder($product->id, $otherStore->id, PriceTierLadder::normalize([
            ['min_quantity' => 3, 'price' => 100],
        ]));

        ProductPriceTier::replaceLadder($product->id, (int) $product->store_id, PriceTierLadder::normalize([
            ['min_quantity' => 10, 'price' => 150.5],
        ]));

        $ladders = ProductPriceTier::laddersFor([(int) $product->store_id, $otherStore->id], [$product->id]);

        $this->assertSame(
            [['min_quantity' => 10.0, 'price' => 150.5]],
            $ladders[$product->store_id.':'.$product->id]
        );
        $this->assertSame(
            [['min_quantity' => 3.0, 'price' => 100.0]],
            $ladders[$otherStore->id.':'.$product->id],
            'лесенка другого магазина не тронута'
        );
    }

    public function test_empty_ladder_deletes_rows(): void
    {
        $product = Product::factory()->create();
        $storeId = (int) $product->store_id;

        ProductPriceTier::replaceLadder($product->id, $storeId, PriceTierLadder::normalize([
            ['min_quantity' => 2, 'price' => 249],
        ]));
        ProductPriceTier::replaceLadder($product->id, $storeId, []);

        $this->assertSame(0, ProductPriceTier::where('product_id', $product->id)->count());
        $this->assertSame([], ProductPriceTier::laddersFor([$storeId], [$product->id]));
    }

    public function test_replace_ladders_writes_many_products_in_bounded_queries(): void
    {
        $store = Store::factory()->create();
        $ladders = [];

        foreach (Product::factory()->count(5)->for($store)->create() as $product) {
            $ladders[$product->id] = PriceTierLadder::normalize([
                ['min_quantity' => 2, 'price' => 90],
                ['min_quantity' => 5, 'price' => 80],
            ]);
        }

        DB::enableQueryLog();
        ProductPriceTier::replaceLadders($store->id, $ladders);
        $writes = collect(DB::getQueryLog())
            ->filter(fn (array $q) => preg_match('/^\s*(insert|delete)\b.+product_price_tiers/i', $q['query']) === 1)
            ->count();
        DB::disableQueryLog();

        $this->assertSame(2, $writes, 'один DELETE и один INSERT на пачку');
        $this->assertSame(10, ProductPriceTier::where('store_id', $store->id)->count());
    }

    public function test_order_item_casts_price_tiers_to_array(): void
    {
        $order = Order::factory()->create();
        $snapshot = [
            'base_price' => 279.0,
            'base_price_old' => null,
            'base_is_eligible' => true,
            'tiers' => [['min_quantity' => 2.0, 'price' => 249.0]],
        ];

        $item = OrderItem::factory()->for($order)->create(['price_tiers' => $snapshot]);

        // assertEquals: MySQL JSON не сохраняет порядок ключей, снапшот из БД сравнивается без учёта порядка.
        $this->assertEquals($snapshot, $item->fresh()->price_tiers);
        $this->assertNull(OrderItem::factory()->for($order)->create()->fresh()->price_tiers);
    }
}
