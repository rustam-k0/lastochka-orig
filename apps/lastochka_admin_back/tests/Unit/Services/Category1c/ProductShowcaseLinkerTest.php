<?php

namespace Tests\Unit\Services\Category1c;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Services\Category1c\ProductShowcaseLinker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Тесты ProductShowcaseLinker — per-product синхронизация витринных категорий
 * товара из его привязки Category1c.showcaseCategories.
 *
 * Сервис — единый источник логики связки product → витрина, используется
 * в SyncCategoryMappingsJob (массово), ProductEventTransformer (inbound 1С-товар)
 * и Category1cObserver (бэкфилл pending). Контракт: связывает ТОЛЬКО когда у
 * Category1c есть привязанные витрины (не затирает админ-товары без category_1c_id
 * и не сбрасывает связку в пустоту).
 */
class ProductShowcaseLinkerTest extends TestCase
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
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();
        return $product;
    }

    /** @test */
    public function links_product_to_showcase_from_category_1c_mapping_and_dispatches_event(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($shop, $cat1c->id);

        $changed = app(ProductShowcaseLinker::class)->sync($product);

        $this->assertTrue($changed, 'sync должен вернуть true при изменении связки');
        $this->assertSame(
            $showcase->id,
            $product->fresh('categories')->categories->first()?->id,
            'Товар должен получить витринную категорию через pivot category_product'
        );
        Event::assertDispatchedTimes(ProductUpdated::class, 1);
    }

    /** @test */
    public function is_idempotent_when_already_linked(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($shop, $cat1c->id);
        $product->categories()->attach($showcase->id, ['position' => 0]);

        $changed = app(ProductShowcaseLinker::class)->sync($product);

        $this->assertFalse($changed, 'Повторный sync без изменений возвращает false');
        Event::assertNotDispatched(ProductUpdated::class);
    }

    /** @test */
    public function does_not_wipe_categories_when_category_1c_has_no_showcase_mapping(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $existing = $this->makeShowcaseLeaf($shop);
        // Category1c без привязанных витрин
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);

        $product = $this->makeProduct($shop, $cat1c->id);
        $product->categories()->attach($existing->id, ['position' => 0]);

        $changed = app(ProductShowcaseLinker::class)->sync($product);

        $this->assertFalse($changed);
        $this->assertSame(
            $existing->id,
            $product->fresh('categories')->categories->first()?->id,
            'Существующая категория НЕ должна затираться при пустом маппинге'
        );
        Event::assertNotDispatched(ProductUpdated::class);
    }

    /** @test */
    public function does_nothing_when_product_has_no_category_1c(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $adminCategory = $this->makeShowcaseLeaf($shop);

        // Админ-товар: category_1c_id = null, привязан к админ-категории напрямую
        $product = $this->makeProduct($shop, null);
        $product->categories()->attach($adminCategory->id, ['position' => 0]);

        $changed = app(ProductShowcaseLinker::class)->sync($product);

        $this->assertFalse($changed);
        $this->assertSame(
            $adminCategory->id,
            $product->fresh('categories')->categories->first()?->id,
            'Админ-товар без category_1c_id не должен трогаться'
        );
        Event::assertNotDispatched(ProductUpdated::class);
    }

    /** @test */
    public function does_not_dispatch_event_when_dispatch_disabled(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($shop, $cat1c->id);

        $changed = app(ProductShowcaseLinker::class)->sync($product, dispatchEvent: false);

        $this->assertTrue($changed);
        $this->assertSame($showcase->id, $product->fresh('categories')->categories->first()?->id);
        Event::assertNotDispatched(ProductUpdated::class);
    }
}
