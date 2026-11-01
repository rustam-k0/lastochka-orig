<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_product_upsert_and_relations(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $store->id]);

        StoreProduct::updateOrCreate(
            ['store_id' => $store->id, 'product_id' => $product->id],
            ['price' => 199.99, 'price_old' => 249.99, 'quantity' => 7, 'synced_at' => now()]
        );
        // повторный upsert не создаёт дубль (UNIQUE store_id+product_id)
        StoreProduct::updateOrCreate(
            ['store_id' => $store->id, 'product_id' => $product->id],
            ['price' => 189.99, 'quantity' => 5]
        );

        $this->assertSame(1, StoreProduct::count());
        $this->assertSame(1, $product->storeProducts()->count());
        $this->assertEquals(189.99, (float) $product->storeProducts->first()->price);
        $this->assertSame(5, (int) $store->storeProducts()->first()->quantity);
    }

    public function test_store_context_returns_constrained_row(): void
    {
        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();
        $product = Product::factory()->create(['store_id' => $storeA->id]);
        StoreProduct::create(['store_id' => $storeA->id, 'product_id' => $product->id, 'price' => 100, 'quantity' => 1]);
        StoreProduct::create(['store_id' => $storeB->id, 'product_id' => $product->id, 'price' => 120, 'quantity' => 2]);

        $loaded = Product::query()
            ->with(['storeProducts' => fn ($q) => $q->where('store_id', $storeB->id)])
            ->find($product->id);

        $this->assertEquals(120.0, (float) $loaded->storeContext()?->price);

        $bare = Product::find($product->id);
        $this->assertNull($bare->storeContext(), 'без constrained-загрузки контекста нет');
    }
}
