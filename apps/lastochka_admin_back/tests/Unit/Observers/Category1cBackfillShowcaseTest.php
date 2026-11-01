<?php

namespace Tests\Unit\Observers;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Observers\Category1cObserver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Регрессионный тест: когда Category1c приезжает позже своих товаров,
 * Category1cObserver не только дозаполняет category_1c_id у pending-товаров,
 * но и привязывает их к витринным категориям (если у Category1c уже есть маппинг).
 */
class Category1cBackfillShowcaseTest extends TestCase
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

    /** @test */
    public function backfill_links_showcase_for_pending_products(): void
    {
        Event::fake([ProductUpdated::class]);

        $shop = $this->makeShop();
        $showcase = $this->makeShowcaseLeaf($shop);
        $cat1c = Category1c::factory()->create(['shop_id' => $shop->id]);
        $cat1c->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        // pending-товар: category_1c_id ещё null, ждёт свою 1С-категорию
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Pending product ' . uniqid();
        $product->category_1c_id = null;
        $product->pending_category_1c_external_id = $cat1c->external_id;
        $product->save();

        (new Category1cObserver())->created($cat1c);

        $product->refresh();
        $this->assertSame($cat1c->id, $product->category_1c_id, 'category_1c_id должен дозаполниться');
        $this->assertNull($product->pending_category_1c_external_id, 'pending должен очиститься');
        $this->assertSame(
            $showcase->id,
            $product->fresh('categories')->categories->first()?->id,
            'pending-товар должен получить витрину при бэкфилле'
        );
        Event::assertDispatched(ProductUpdated::class);
    }
}
