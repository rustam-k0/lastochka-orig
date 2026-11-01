<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Category1c;

use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Category1cSyncBatch;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class SyncCategoryMappingsJobNetworkTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeProduct(Shop $shop, int $cat1cId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();

        return $product;
    }

    private function makeBatch(int $shopId): Category1cSyncBatch
    {
        return Category1cSyncBatch::create([
            'shop_id' => $shopId,
            'status' => 'PENDING',
            'started_at' => now(),
        ]);
    }

    public function test_network_mode_syncs_products_of_all_shops(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $shopB = $this->makeShop('shop-b-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $productA = $this->makeProduct($shopA, $cat1c->id);
        $productB = $this->makeProduct($shopB, $cat1c->id);

        $batch = $this->makeBatch($anchor->id);
        (new SyncCategoryMappingsJob($batch->id, $anchor->id))->handle();

        $this->assertSame('COMPLETED', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->progress_total);
        $this->assertTrue($productA->fresh()->categories->pluck('id')->contains($showcase->id));
        $this->assertTrue($productB->fresh()->categories->pluck('id')->contains($showcase->id));
    }

    public function test_per_shop_mode_ignores_foreign_shop_products(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $shopA = $this->makeShop('shop-a-' . uniqid());
        $this->disableCategory1cNetworkMode();

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $cat1c = Category1c::factory()->create(['shop_id' => $anchor->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $productAnchor = $this->makeProduct($anchor, $cat1c->id);
        $productA = $this->makeProduct($shopA, $cat1c->id);

        $batch = $this->makeBatch($anchor->id);
        (new SyncCategoryMappingsJob($batch->id, $anchor->id))->handle();

        $this->assertTrue($productAnchor->fresh()->categories->pluck('id')->contains($showcase->id));
        $this->assertFalse($productA->fresh()->categories->pluck('id')->contains($showcase->id));
    }
}
