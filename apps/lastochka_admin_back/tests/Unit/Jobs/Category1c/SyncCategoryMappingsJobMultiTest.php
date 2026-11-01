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
use Tests\TestCase;

/**
 * Тесты SyncCategoryMappingsJob под M:N: один товар может получить
 * несколько витринных категорий из Category1c.showcaseCategories().
 */
class SyncCategoryMappingsJobMultiTest extends TestCase
{
    use DatabaseTransactions;

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeShop(): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . uniqid(),
            'code' => 'fix-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeCategory(): Category
    {
        return Category::create([
            'title' => 'Cat ' . uniqid(),
            'parent_id' => null,
        ]);
    }

    private function makeProduct(Shop $shop, ?int $cat1cId = null): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();
        return $product;
    }

    private function makeBatch(Shop $shop): Category1cSyncBatch
    {
        return Category1cSyncBatch::create([
            'shop_id' => $shop->id,
            'status' => 'PENDING',
            'progress_total' => 0,
            'progress_done' => 0,
            'started_at' => now(),
        ]);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_job_assigns_all_mapped_showcases_to_product(): void
    {
        $shop = $this->makeShop();
        $c1c = Category1c::factory()->create(['shop_id' => $shop->id]);

        $sA = $this->makeCategory();
        $sB = $this->makeCategory();
        $sC = $this->makeCategory();
        $c1c->showcaseCategories()->attach([
            $sA->id => ['position' => 0],
            $sB->id => ['position' => 1],
            $sC->id => ['position' => 2],
        ]);

        $product = $this->makeProduct($shop, $c1c->id);
        $batch = $this->makeBatch($shop);

        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        $linked = $product->fresh('categories')->categories->pluck('id')->sort()->values()->all();
        $expected = [$sA->id, $sB->id, $sC->id];
        sort($expected);
        $this->assertSame($expected, $linked);

        $batch->refresh();
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(1, $batch->metadata['touched_products'] ?? null);
    }

    /** @test */
    public function test_job_overwrites_manually_added_category(): void
    {
        $shop = $this->makeShop();
        $c1c = Category1c::factory()->create(['shop_id' => $shop->id]);

        $sA = $this->makeCategory();
        $manual = $this->makeCategory();
        $c1c->showcaseCategories()->attach($sA->id, ['position' => 0]);

        $product = $this->makeProduct($shop, $c1c->id);
        // Вручную добавляем «левую» категорию — job должен её убрать.
        $product->categories()->attach($manual->id, ['position' => 0]);

        $batch = $this->makeBatch($shop);

        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        // Полная перезапись — manual удалён, остался только sA из маппинга.
        $linked = $product->fresh('categories')->categories->pluck('id')->all();
        $this->assertSame([$sA->id], $linked);
    }

    /** @test */
    public function test_job_idempotent_no_changes_when_already_synced(): void
    {
        $shop = $this->makeShop();
        $c1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $sA = $this->makeCategory();
        $sB = $this->makeCategory();
        $c1c->showcaseCategories()->attach([
            $sA->id => ['position' => 0],
            $sB->id => ['position' => 1],
        ]);

        $product = $this->makeProduct($shop, $c1c->id);
        // Продукт уже синхронизирован — те же категории с теми же position'ами.
        $product->categories()->sync([
            $sA->id => ['position' => 0],
            $sB->id => ['position' => 1],
        ]);

        $batch = $this->makeBatch($shop);

        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        $batch->refresh();
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(0, $batch->metadata['touched_products'] ?? null);
    }
}
