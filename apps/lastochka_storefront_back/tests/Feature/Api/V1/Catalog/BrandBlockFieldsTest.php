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

final class BrandBlockFieldsTest extends TestCase
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

    private function createBrand(array $attributes = []): Brand
    {
        return Brand::factory()->create(array_merge([
            'title' => 'IEK',
            'link' => 'https://iek.ru',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_path' => 'brands/certificates/cert.pdf',
            'certificate_name' => 'Сертификат официального дилера.pdf',
        ], $attributes));
    }

    public function test_product_card_returns_brand_block_fields(): void
    {
        $store = Store::factory()->create();
        $brand = $this->createBrand();

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => 4.0,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => 5.0,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $response->assertJsonPath('data.brand.id', $brand->id);
        $response->assertJsonPath('data.brand.title', 'IEK');
        $response->assertJsonPath('data.brand.link', 'https://iek.ru');
        $response->assertJsonPath('data.brand.countryOrigin', 'Россия');
        $response->assertJsonPath('data.brand.countryProduction', 'Китай');
        $response->assertJsonPath('data.brand.certificateName', 'Сертификат официального дилера.pdf');
        $response->assertJsonPath('data.brand.rating', 4.5);

        $certificate = $response->json('data.brand.certificate');
        $this->assertNotNull($certificate);
        $this->assertStringContainsString('brands/certificates/cert.pdf', $certificate);
        $this->assertStringStartsWith('http', $certificate);
    }

    public function test_brand_rating_excludes_inactive_products(): void
    {
        $store = Store::factory()->create();
        $brand = $this->createBrand();

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => 4.0,
        ]);
        // Неактивный товар с низким рейтингом не должен влиять на среднее.
        Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => 1.0,
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $this->assertEquals(4.0, $response->json('data.brand.rating'));
    }

    public function test_brand_rating_null_without_rated_products(): void
    {
        $store = Store::factory()->create();
        $brand = $this->createBrand();

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => null,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $response->assertJsonPath('data.brand.rating', null);
    }

    public function test_brand_without_certificate_returns_nulls(): void
    {
        $store = Store::factory()->create();
        $brand = $this->createBrand([
            'link' => null,
            'country_origin' => null,
            'country_production' => null,
            'certificate_path' => null,
            'certificate_name' => null,
        ]);

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();

        $response->assertJsonPath('data.brand.link', null);
        $response->assertJsonPath('data.brand.countryOrigin', null);
        $response->assertJsonPath('data.brand.countryProduction', null);
        $response->assertJsonPath('data.brand.certificate', null);
        $response->assertJsonPath('data.brand.certificateName', null);
    }

    public function test_brands_index_returns_block_fields_and_rating(): void
    {
        $store = Store::factory()->create();
        $brand = $this->createBrand();

        Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => 3.0,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'brand_id' => $brand->id,
            'rating' => 4.0,
        ]);

        $response = $this->getJson('/api/v1/brands')->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', $brand->id);
        $this->assertNotNull($item);
        $this->assertSame('https://iek.ru', $item['link']);
        $this->assertSame('Россия', $item['countryOrigin']);
        $this->assertSame('Китай', $item['countryProduction']);
        $this->assertSame('Сертификат официального дилера.pdf', $item['certificateName']);
        $this->assertSame(3.5, $item['rating']);
        $this->assertStringContainsString('brands/certificates/cert.pdf', $item['certificate']);
    }
}
