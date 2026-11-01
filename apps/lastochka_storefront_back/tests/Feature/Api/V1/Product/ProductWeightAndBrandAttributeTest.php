<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-12190: weight в JSON товара + бренд как виртуальный
 * атрибут в массиве attributes (мобилка рисует блок «Характеристики»
 * из attributes без релиза приложения).
 */
final class ProductWeightAndBrandAttributeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(ModuleConfigService::class)->invalidateCache();

        parent::tearDown();
    }

    public function test_product_card_returns_weight_in_grams(): void
    {
        $product = $this->makeProduct(['weight' => 230]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.weight', 230);
    }

    public function test_product_card_returns_null_weight_when_not_filled(): void
    {
        $product = $this->makeProduct(['weight' => null]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('weight', $data);
        $this->assertNull($data['weight']);
    }

    public function test_brand_is_appended_to_attributes_when_module_enabled(): void
    {
        $this->setBrandsModule(true);

        $brand = Brand::factory()->create(['title' => 'Ласточка']);
        $product = $this->makeProduct(['brand_id' => $brand->id]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'product_type',
            'name' => 'Тип продукта',
            'value' => 'Выпечка',
            'position' => 0,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $attributes = collect($response->json('data.attributes'));
        $brandAttribute = $attributes->firstWhere('key', 'Бренд');

        $this->assertNotNull($brandAttribute, 'Бренд должен попасть в attributes');
        $this->assertSame('Ласточка', $brandAttribute['value']);
        $this->assertSame(1, $brandAttribute['sortOrder'], 'Бренд идёт после реальных атрибутов');
        // Структурный ключ brand при этом остаётся.
        $this->assertSame('Ласточка', $response->json('data.brand.title'));
    }

    public function test_brand_attribute_absent_when_module_disabled(): void
    {
        $brand = Brand::factory()->create(['title' => 'Ласточка']);
        $product = $this->makeProduct(['brand_id' => $brand->id]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $attributes = collect($response->json('data.attributes'));
        $this->assertNull($attributes->firstWhere('key', 'Бренд'));
    }

    public function test_brand_attribute_absent_when_product_has_no_brand(): void
    {
        $this->setBrandsModule(true);

        $product = $this->makeProduct();

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk();

        $attributes = collect($response->json('data.attributes'));
        $this->assertNull($attributes->firstWhere('key', 'Бренд'));
    }

    private function makeProduct(array $attributes = []): Product
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        return Product::factory()->for($store)->inCategory($category)->create(array_merge([
            'price' => 100,
            'quantity' => 10,
        ], $attributes));
    }

    private function setBrandsModule(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    ModuleConfigService::BRANDS_KEY => [
                        'label' => 'Бренды',
                        'enabled' => $enabled,
                    ],
                ]),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }
}
