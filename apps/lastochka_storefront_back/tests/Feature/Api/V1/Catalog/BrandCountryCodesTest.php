<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BrandCountryCodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_product_card_returns_country_codes(): void
    {
        $store = Store::factory()->create();
        $brand = Brand::factory()->create([
            'title' => 'IEK',
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
            'country_production' => 'Китай',
            'country_production_code' => 'CN',
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $response->assertJsonPath('data.brand.countryOriginCode', 'RU');
        $response->assertJsonPath('data.brand.countryProductionCode', 'CN');
        // Названия остаются: клиент выводит их текстом.
        $response->assertJsonPath('data.brand.countryOrigin', 'Россия');
        $response->assertJsonPath('data.brand.countryProduction', 'Китай');
    }

    public function test_brand_without_codes_returns_nulls(): void
    {
        $store = Store::factory()->create();
        $brand = Brand::factory()->create([
            'title' => 'Без кодов',
            'country_origin' => 'Нарния',
            'country_origin_code' => null,
            'country_production' => null,
            'country_production_code' => null,
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $response->assertJsonPath('data.brand.countryOriginCode', null);
        $response->assertJsonPath('data.brand.countryProductionCode', null);
        $response->assertJsonPath('data.brand.countryOrigin', 'Нарния');
    }
}
