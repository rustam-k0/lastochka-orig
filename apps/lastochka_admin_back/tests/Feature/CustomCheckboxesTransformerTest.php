<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\MerchantBanner;
use App\Models\MerchantProductGroup;
use App\Models\MerchantSale;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Story;
use App\Transformers\BrandEventTransformer;
use App\Transformers\CategoryEventTransformer;
use App\Transformers\MerchantBannerEventTransformer;
use App\Transformers\MerchantProductGroupEventTransformer;
use App\Transformers\MerchantSaleEventTransformer;
use App\Transformers\ProductEventTransformer;
use App\Transformers\ShopEventTransformer;
use App\Transformers\StoryEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomCheckboxesTransformerTest extends TestCase
{
    use DatabaseTransactions;

    private const MAP = ['is_hit' => true, 'is_eco' => false];

    private function makeShop(): Shop
    {
        return Shop::create([
            'title' => 'Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    private function checkboxesJson(): string
    {
        return json_encode((object) self::MAP);
    }

    /** @test */
    public function brand_event_contains_custom_checkboxes(): void
    {
        $brand = Brand::create(['title' => 'B ' . uniqid(), 'position' => 0, 'is_active' => true, 'custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(BrandEventTransformer::class)->toEvent($brand);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function brand_event_defaults_to_empty_map(): void
    {
        $brand = Brand::create(['title' => 'B ' . uniqid(), 'position' => 0, 'is_active' => true]);
        $event = app(BrandEventTransformer::class)->toEvent($brand);
        $this->assertSame([], $event['custom_checkboxes']);
    }

    /** @test */
    public function category_event_contains_custom_checkboxes(): void
    {
        $category = Category::create(['title' => 'C ' . uniqid(), 'custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(CategoryEventTransformer::class)->toEvent($category);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function story_event_contains_custom_checkboxes(): void
    {
        $story = Story::create(['title' => 'S ' . uniqid(), 'expires_at' => now()->addMonth(), 'custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(StoryEventTransformer::class)->toEvent($story);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function merchant_banner_event_contains_custom_checkboxes(): void
    {
        $banner = MerchantBanner::create(['title' => 'MB ' . uniqid(), 'shop_id' => $this->makeShop()->id, 'is_active' => true, 'position' => 0, 'custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(MerchantBannerEventTransformer::class)->toEvent($banner);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function merchant_sale_event_contains_custom_checkboxes(): void
    {
        $sale = MerchantSale::create(['title' => 'MS ' . uniqid(), 'shop_id' => $this->makeShop()->id, 'discount_percent' => 10, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'is_active' => true, 'custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(MerchantSaleEventTransformer::class)->toEvent($sale);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function merchant_product_group_event_contains_custom_checkboxes(): void
    {
        $group = MerchantProductGroup::create(['shop_id' => $this->makeShop()->id, 'title' => 'MPG ' . uniqid(), 'is_active' => true, 'custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(MerchantProductGroupEventTransformer::class)->toEvent($group);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function shop_event_contains_custom_checkboxes(): void
    {
        $shop = $this->makeShop();
        $shop->update(['custom_checkboxes' => $this->checkboxesJson()]);
        $event = app(ShopEventTransformer::class)->toEvent($shop->fresh());
        // assertEqualsCanonicalizing: MySQL JSON-колонка нормализует порядок ключей объекта
        // при round-trip через fresh(), значения/ключи те же — сравниваем как множество пар.
        $this->assertEqualsCanonicalizing(self::MAP, $event['custom_checkboxes']);
    }

    /** @test */
    public function product_event_contains_custom_checkboxes(): void
    {
        $product = Product::create([
            'title' => 'P ' . uniqid(),
            'shop_id' => $this->makeShop()->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'fill_percent' => 0,
            'publish_after_unlock' => false,
            'custom_checkboxes' => $this->checkboxesJson(),
        ]);
        $event = app(ProductEventTransformer::class)->toEvent($product);
        $this->assertSame(self::MAP, $event['custom_checkboxes']);
    }
}
