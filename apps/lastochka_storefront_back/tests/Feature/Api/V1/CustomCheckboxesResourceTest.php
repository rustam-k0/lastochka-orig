<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\Story;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomCheckboxesResourceTest extends TestCase
{
    use RefreshDatabase;

    private const MAP = ['is_hit' => true];

    public function test_product_show_returns_custom_checkboxes(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create(['custom_checkboxes' => self::MAP]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.customCheckboxes.is_hit', true);
    }

    public function test_categories_index_returns_custom_checkboxes(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['custom_checkboxes' => self::MAP]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/categories")->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('customCheckboxes.is_hit')->contains(true));
    }

    public function test_stories_index_returns_custom_checkboxes(): void
    {
        Story::factory()->create(['custom_checkboxes' => self::MAP, 'is_active' => true, 'expires_at' => now()->addMonth()]);

        $response = $this->getJson('/api/v1/stories')->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('customCheckboxes.is_hit')->contains(true));
    }

    public function test_brands_index_returns_custom_checkboxes(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'brands' => ['type' => 'boolean', 'enabled' => true],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();

        Brand::factory()->create(['custom_checkboxes' => self::MAP, 'is_active' => true]);

        $response = $this->getJson('/api/v1/brands')->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('customCheckboxes.is_hit')->contains(true));
    }

    public function test_banners_index_returns_custom_checkboxes(): void
    {
        $store = Store::factory()->create();
        Banner::factory()->create(['store_id' => $store->id, 'custom_checkboxes' => self::MAP, 'is_active' => true]);

        $response = $this->getJson("/api/v1/stores/{$store->id}/banners")->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('customCheckboxes.is_hit')->contains(true));
    }

    public function test_promotions_index_returns_custom_checkboxes(): void
    {
        $store = Store::factory()->create();
        Promotion::factory()->create([
            'store_id' => $store->id,
            'custom_checkboxes' => self::MAP,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $response = $this->getJson('/api/v1/promotions')->assertOk();
        $this->assertTrue(collect($response->json('data'))->pluck('customCheckboxes.is_hit')->contains(true));
    }

    public function test_product_group_show_returns_custom_checkboxes(): void
    {
        $store = Store::factory()->create();
        $group = ProductGroup::factory()->create(['store_id' => $store->id, 'custom_checkboxes' => self::MAP, 'is_active' => true]);

        $this->getJson("/api/v1/product-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.customCheckboxes.is_hit', true);
    }

    public function test_store_show_returns_custom_checkboxes(): void
    {
        $store = Store::factory()->create(['custom_checkboxes' => self::MAP]);

        $this->getJson("/api/v1/stores/{$store->id}")
            ->assertOk()
            ->assertJsonPath('data.customCheckboxes.is_hit', true);
    }
}
