<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CategoryFlagFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();
    }

    private function seedFlagConfig(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => 'entity_checkboxes'],
            ['value' => json_encode(['Product' => [
                ['field' => 'is_hit', 'label' => 'Хит продаж', 'enabled' => true, 'custom' => true, 'filterable' => true],
                ['field' => 'is_eco', 'label' => 'Эко', 'enabled' => true, 'custom' => true, 'filterable' => false],
            ]]), 'type' => 'json'],
        );
    }

    private function makeProduct(array $attrs = []): Product
    {
        return Product::factory()
            ->for($this->store)
            ->inCategory($this->category)
            ->create(array_merge(['price' => 500], $attrs));
    }

    private function filtersEndpoint(): string
    {
        return "/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/filters";
    }

    private function productsEndpoint(): string
    {
        return "/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products";
    }

    public function test_filters_include_flag_section_when_product_has_true(): void
    {
        $this->seedFlagConfig();
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => true]]);
        $this->makeProduct();

        $response = $this->getJson($this->filtersEndpoint())->assertOk();
        $section = collect($response->json('data'))->firstWhere('code', 'is_hit');

        $this->assertNotNull($section);
        $this->assertSame('Хит продаж', $section['name']);
        $this->assertSame('flag', $section['type']);
    }

    public function test_filters_hide_flag_without_matching_products(): void
    {
        $this->seedFlagConfig();
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => false]]);

        $response = $this->getJson($this->filtersEndpoint())->assertOk();
        $this->assertNull(collect($response->json('data'))->firstWhere('code', 'is_hit'));
    }

    public function test_filters_hide_non_filterable_flag(): void
    {
        $this->seedFlagConfig();
        $this->makeProduct(['custom_checkboxes' => ['is_eco' => true]]);

        $response = $this->getJson($this->filtersEndpoint())->assertOk();
        $this->assertNull(collect($response->json('data'))->firstWhere('code', 'is_eco'));
    }

    public function test_listing_filters_by_flag(): void
    {
        $this->seedFlagConfig();
        $hit = $this->makeProduct(['custom_checkboxes' => ['is_hit' => true], 'title' => 'Хит-товар']);
        $this->makeProduct(['title' => 'Обычный']);
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => false], 'title' => 'Явный false']);

        $response = $this->getJson($this->productsEndpoint() . '?filter[is_hit]=1')->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame($hit->id, $response->json('data.0.id'));
    }

    public function test_count_only_respects_flag_filter(): void
    {
        $this->seedFlagConfig();
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => true]]);
        $this->makeProduct();

        $response = $this->getJson($this->productsEndpoint() . '?filter[is_hit]=1&countOnly=1')->assertOk();
        $this->assertSame(1, $response->json('total'));
    }

    public function test_unknown_flag_code_falls_back_to_attribute_branch(): void
    {
        $this->seedFlagConfig();
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => true]]);

        // is_new не в настройках → атрибутная ветка → нет совпадений
        $response = $this->getJson($this->productsEndpoint() . '?filter[is_new]=1')->assertOk();
        $this->assertSame(0, $response->json('total'));
    }

    public function test_flag_section_skipped_when_code_collides_with_attribute(): void
    {
        $this->seedFlagConfig();
        $product = $this->makeProduct(['custom_checkboxes' => ['is_hit' => true]]);

        // Атрибут с кодом, совпадающим со слагом чекбокса
        \App\Models\ProductAttribute::create([
            'product_id' => $product->id,
            'code' => 'is_hit',
            'name' => 'Хит (атрибут)',
            'value' => 'да',
            'attribute_value_id' => 77,
            'attribute_value_code' => 'da',
            'position' => 0,
        ]);

        $response = $this->getJson($this->filtersEndpoint())->assertOk();
        $sections = collect($response->json('data'))->where('code', 'is_hit');

        $this->assertCount(1, $sections);
        $this->assertSame('checkbox', $sections->first()['type']);
    }

    public function test_flag_section_count_follows_other_filters(): void
    {
        $this->seedFlagConfig();
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => true], 'price' => 100]);
        $this->makeProduct(['custom_checkboxes' => ['is_hit' => true], 'price' => 900]);
        $this->makeProduct(['price' => 900]);

        $flag = fn (string $query) => collect($this->getJson($this->filtersEndpoint() . $query)->assertOk()->json('data'))
            ->firstWhere('code', 'is_hit');

        $this->assertSame(2, $flag('')['count']);
        $this->assertTrue($flag('')['available']);

        $this->assertSame(1, $flag('?priceMin=500')['count']);

        // Под выбранные фильтры не подходит ни один хит — секция остаётся,
        // но недоступна.
        $this->assertSame(0, $flag('?priceMin=1000')['count']);
        $this->assertFalse($flag('?priceMin=1000')['available']);
    }
}
