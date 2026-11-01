<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Product;

use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Поля единиц измерения у вариантов конфигурируемого товара:
 * weight/measurementUnit/measurementUnitLabel нужны фронту для подписи
 * объёма («0,75 л») на карточке при выборе варианта.
 */
final class ProductVariantUnitFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function enableConfigurableProducts(): void
    {
        Cache::flush();
        StorefrontSetting::updateOrCreate(
            ['key' => 'project_modules'],
            ['value' => json_encode(['configurable_products' => ['enabled' => true]]), 'type' => 'json'],
        );
    }

    public function test_variants_contain_unit_fields(): void
    {
        $this->enableConfigurableProducts();

        $store = Store::factory()->create();
        $parent = Product::factory()->for($store)->create([
            'is_configurable' => true,
            'quantity' => 0,
        ]);
        $small = Product::factory()->for($store)->create([
            'parent_id' => $parent->id,
            'quantity' => 5,
            'weight' => 450,
            'measurement_unit' => 'milliliter',
        ]);
        $big = Product::factory()->for($store)->create([
            'parent_id' => $parent->id,
            'quantity' => 5,
            'weight' => 750,
            'measurement_unit' => 'liter',
        ]);

        $response = $this->getJson("/api/v1/products/{$parent->id}");

        $response->assertOk();
        $variants = collect($response->json('data.variants'))->keyBy('id');

        $this->assertSame(450, $variants[$small->id]['weight']);
        $this->assertSame('milliliter', $variants[$small->id]['measurementUnit']);
        $this->assertSame('мл', $variants[$small->id]['measurementUnitLabel']);

        $this->assertSame(750, $variants[$big->id]['weight']);
        $this->assertSame('liter', $variants[$big->id]['measurementUnit']);
        $this->assertSame('л', $variants[$big->id]['measurementUnitLabel']);
    }
}
