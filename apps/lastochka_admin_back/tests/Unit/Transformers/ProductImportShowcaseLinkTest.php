<?php

namespace Tests\Unit\Transformers;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Регрессионный тест: при приёме 1С-товара из RabbitMQ (afterCreate/afterUpdate)
 * товар должен автоматически привязываться к витринным категориям, замапленным
 * на его Category1c — без ручного запуска SyncCategoryMappingsJob.
 *
 * Баг: новые товары из 1С получали category_1c_id, но не получали витринную
 * связку (category_product) → были невидимы в курьерке и на витрине, пока
 * вручную не перезапускали синк маппинга.
 */
class ProductImportShowcaseLinkTest extends TestCase
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

    private function makeShowcaseLeaf(Shop $shop): Category
    {
        $cat = Category::create([
            'title' => 'Showcase ' . uniqid(),
            'link' => 'sc-' . uniqid(),
        ]);
        ShopCategory::firstOrCreate(['shop_id' => $shop->id, 'category_id' => $cat->id]);
        return $cat;
    }

    private function makeProduct(Shop $shop, ?int $cat1cId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();
        return $product;
    }

    /** @test */
    public function after_update_links_showcase_categories_for_1c_product(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($shop, $cat1c->id);

        (new ProductEventTransformer())->afterUpdate($product, ['shop_code' => $shop->code]);

        $this->assertSame(
            $showcase->id,
            $product->fresh('categories')->categories->first()?->id,
            'Товар из 1С должен получить витрину при afterUpdate'
        );
        Event::assertDispatched(ProductUpdated::class);
    }

    /** @test */
    public function after_create_links_showcase_categories_for_1c_product(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($shop, $cat1c->id);

        (new ProductEventTransformer())->afterCreate($product, ['shop_code' => $shop->code]);

        $this->assertSame(
            $showcase->id,
            $product->fresh('categories')->categories->first()?->id,
            'Товар из 1С должен получить витрину при afterCreate'
        );
    }
}
