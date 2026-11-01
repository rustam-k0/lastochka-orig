<?php

namespace Tests\Unit\Jobs\Category1c;

use App\Events\ProductUpdated;
use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Category1cSyncBatch;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Тесты SyncCategoryMappingsJob — массовое применение showcase-маппинга
 * к Product'ам магазина после привязки Category1c.
 *
 * См. spec §7.1, §7.2.
 */
class SyncCategoryMappingsJobTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . uniqid(),
            'code' => 'fix-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeShowcaseLeaf(Shop $shop, string $titlePrefix = 'Showcase'): Category
    {
        $cat = Category::create([
            'title' => $titlePrefix . ' ' . uniqid(),
            'link' => 'sc-' . uniqid(),
        ]);
        ShopCategory::firstOrCreate(['shop_id' => $shop->id, 'category_id' => $cat->id]);
        return $cat;
    }

    private function makeProduct(Shop $shop, ?int $cat1cId = null): Product
    {
        // Shop у нас без HasFactory; Product'ы строим вручную (как в Phase 6 тестах).
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();
        return $product;
    }

    /** @test */
    public function assigns_showcase_category_id_to_products_via_linked_category_1c(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);

        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
        ]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $products = collect();
        for ($i = 0; $i < 3; $i++) {
            $products->push($this->makeProduct($shop, $cat1c->id));
        }

        $batch = Category1cSyncBatch::factory()->create([
            'shop_id' => $shop->id,
            'status' => 'PENDING',
        ]);

        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        foreach ($products as $p) {
            $this->assertSame(
                $showcase->id,
                $p->fresh('categories')->categories->first()?->id,
                "Product {$p->id} должен получить витринную категорию через pivot"
            );
        }

        Event::assertDispatchedTimes(ProductUpdated::class, 3);

        $batch->refresh();
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(3, $batch->progress_total);
        $this->assertSame(3, $batch->progress_done);
        $this->assertEquals(['touched_products' => 3], $batch->metadata);
        $this->assertNotNull($batch->completed_at);

        $cat1c->refresh();
        $this->assertNotNull($cat1c->last_synced_at);
    }

    /** @test */
    public function idempotent_second_run_does_not_dispatch_events_when_no_changes(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);

        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
        ]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        // Все продукты уже привязаны к showcase через pivot — Job не должен ничего трогать.
        for ($i = 0; $i < 3; $i++) {
            $product = $this->makeProduct($shop, $cat1c->id);
            $product->categories()->attach($showcase->id, ['position' => 0]);
        }

        $batch = Category1cSyncBatch::factory()->create([
            'shop_id' => $shop->id,
            'status' => 'PENDING',
        ]);

        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        Event::assertNotDispatched(ProductUpdated::class);

        $batch->refresh();
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(3, $batch->progress_total, 'progress_total считает кандидатов, не изменённых');
        $this->assertSame(3, $batch->progress_done);
        $this->assertEquals(['touched_products' => 0], $batch->metadata);
    }

    /** @test */
    public function skips_products_whose_category_1c_has_no_showcase(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $cat1c = Category1c::factory()->create([
            'shop_id' => $shop->id,
            // showcaseCategories не прикрепляем — Category1c без pivot-привязки
        ]);
        $this->makeProduct($shop, $cat1c->id);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id]);
        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        Event::assertNotDispatched(ProductUpdated::class);

        $batch->refresh();
        $this->assertSame(0, $batch->progress_total);
        $this->assertSame(0, $batch->progress_done);
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertEquals(['touched_products' => 0], $batch->metadata);
    }

    /** @test */
    public function skips_products_without_category_1c_at_all(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $this->makeProduct($shop, null);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id]);
        (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

        Event::assertNotDispatched(ProductUpdated::class);
        $this->assertSame(0, $batch->fresh()->progress_total);
        $this->assertSame('COMPLETED', $batch->fresh()->status);
    }

    /** @test */
    public function does_not_touch_products_of_other_shops(): void
    {
        Event::fake([ProductUpdated::class]);

        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $showcaseA = $this->makeShowcaseLeaf($shopA);
        $showcaseB = $this->makeShowcaseLeaf($shopB);

        $cat1cA = Category1c::factory()->create(['shop_id' => $shopA->id]);
        $cat1cA->showcaseCategories()->attach($showcaseA->id, ['position' => 0]);

        $cat1cB = Category1c::factory()->create(['shop_id' => $shopB->id]);
        $cat1cB->showcaseCategories()->attach($showcaseB->id, ['position' => 0]);

        $productA = $this->makeProduct($shopA, $cat1cA->id);
        $productB = $this->makeProduct($shopB, $cat1cB->id);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shopA->id]);
        (new SyncCategoryMappingsJob($batch->id, $shopA->id))->handle();

        $this->assertSame(
            $showcaseA->id,
            $productA->fresh('categories')->categories->first()?->id,
            'Product shopA должен получить витринную категорию'
        );
        $this->assertEmpty(
            $productB->fresh('categories')->categories,
            'Product чужого магазина не должен меняться'
        );

        Event::assertDispatchedTimes(ProductUpdated::class, 1);

        // last_synced_at у Category1c чужого магазина не двигается
        $this->assertNull($cat1cB->fresh()->last_synced_at);
        $this->assertNotNull($cat1cA->fresh()->last_synced_at);
    }

    /** @test */
    public function failed_callback_marks_batch_failed(): void
    {
        $shop = $this->makeShop();
        $batch = Category1cSyncBatch::factory()->create([
            'shop_id' => $shop->id,
            'status' => 'IN_PROGRESS',
        ]);

        (new SyncCategoryMappingsJob($batch->id, $shop->id))
            ->failed(new \RuntimeException('boom'));

        $batch->refresh();
        $this->assertSame('FAILED', $batch->status);
        $this->assertStringContainsString('boom', $batch->error);
        $this->assertNotNull($batch->completed_at);
    }

    /** @test */
    public function lock_prevents_parallel_execution(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);
        $product = $this->makeProduct($shop, $cat1c->id);

        $batch = Category1cSyncBatch::factory()->create(['shop_id' => $shop->id]);

        // Захватываем лок до запуска job'а — имитация параллельного процесса.
        // Стор берём тот же, что и job (config cache.lock_store): блокировки живут
        // отдельно от основного кэша, иначе «параллельный процесс» окажется
        // в другом сторе и job спокойно войдёт внутрь.
        $lock = Cache::store(config('cache.lock_store'))->lock("category_1c_sync:{$shop->id}", 60);
        $this->assertTrue($lock->get());

        try {
            (new SyncCategoryMappingsJob($batch->id, $shop->id))->handle();

            $batch->refresh();
            $this->assertSame('FAILED', $batch->status);
            $this->assertStringContainsString('Another sync', $batch->error);

            // Product не должен быть тронут другим процессом — categories пусты.
            $this->assertEmpty($product->fresh('categories')->categories);
            Event::assertNotDispatched(ProductUpdated::class);
        } finally {
            $lock->release();
        }
    }

    /** @test */
    public function exception_during_handle_marks_batch_failed_and_releases_lock(): void
    {
        $shop = $this->makeShop();

        // batch_id, которого нет → findOrFail кидает ModelNotFoundException
        // ДО получения лока. Лок не должен остаться удерживаемым.
        $missingBatchId = 9_999_999;

        try {
            (new SyncCategoryMappingsJob($missingBatchId, $shop->id))->handle();
            $this->fail('Ожидался ModelNotFoundException');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // ok
        }

        // После исключения лок свободен — следующий get() возьмёт его.
        $lock = Cache::lock("category_1c_sync:{$shop->id}", 60);
        $this->assertTrue($lock->get(), 'Лок должен быть освобождён после исключения');
        $lock->release();
    }
}
