<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();
    }

    private function endpoint(): string
    {
        return "/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/filters";
    }

    private function makeProduct(int $price = 500): Product
    {
        return Product::factory()
            ->for($this->store)
            ->inCategory($this->category)
            ->create(['price' => $price]);
    }

    private function attachAttribute(
        Product $product,
        string $code,
        string $name,
        ?int $attributeValueId,
        ?string $attributeValueCode,
        ?string $value,
        int $position = 0,
    ): ProductAttribute {
        return ProductAttribute::create([
            'product_id' => $product->id,
            'code' => $code,
            'name' => $name,
            'value' => (string) $value,
            'attribute_value_id' => $attributeValueId,
            'attribute_value_code' => $attributeValueCode,
            'position' => $position,
        ]);
    }

    public function test_returns_price_section_with_min_and_max(): void
    {
        $this->makeProduct(150);
        $this->makeProduct(900);
        $this->makeProduct(400);

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $price = collect($response->json('data'))->firstWhere('code', 'price');
        $this->assertNotNull($price);
        $this->assertSame('range', $price['type']);
        $this->assertSame(150, $price['min']);
        $this->assertSame(900, $price['max']);
    }

    public function test_price_section_is_first(): void
    {
        $this->makeProduct(100);

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $this->assertSame('price', $response->json('data.0.code'));
    }

    public function test_aggregates_select_attributes_across_category_products(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();

        $this->attachAttribute($p1, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider Electric');
        $this->attachAttribute($p2, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');
        $this->attachAttribute($p3, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider Electric');

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $manufacturer = collect($response->json('data'))->firstWhere('code', 'manufacturer');

        $this->assertNotNull($manufacturer);
        $this->assertSame('Производитель', $manufacturer['name']);
        $this->assertSame('checkbox', $manufacturer['type']);
        $this->assertCount(2, $manufacturer['values']);

        $codes = collect($manufacturer['values'])->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['schneider', 'abb'], $codes);
    }

    public function test_excludes_non_select_attributes(): void
    {
        $p = $this->makeProduct();
        $this->attachAttribute($p, 'description', 'Описание', null, null, 'Какой-то текст');
        $this->attachAttribute($p, 'width_mm', 'Ширина, мм', null, null, '300');

        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertNotContains('description', $codes);
        $this->assertNotContains('width_mm', $codes);
    }

    public function test_only_active_and_priced_products_counted(): void
    {
        $active = $this->makeProduct();
        $inactive = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => 500,
            'is_active' => false,
        ]);
        $noPrice = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'price' => 0,
            'is_configurable' => false,
        ]);

        $this->attachAttribute($active, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider Electric');
        $this->attachAttribute($inactive, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');
        $this->attachAttribute($noPrice, 'manufacturer', 'Производитель', 12, 'legrand', 'Legrand');

        $response = $this->getJson($this->endpoint());

        $manufacturer = collect($response->json('data'))->firstWhere('code', 'manufacturer');
        $codes = collect($manufacturer['values'])->pluck('code')->all();
        $this->assertSame(['schneider'], $codes);
    }

    public function test_returns_empty_sections_for_empty_category(): void
    {
        $response = $this->getJson($this->endpoint());

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('price', $data[0]['code']);
        $this->assertSame(0, $data[0]['min']);
        $this->assertSame(0, $data[0]['max']);
    }

    public function test_value_uses_attribute_value_code_fallback_when_value_missing(): void
    {
        $p = $this->makeProduct();
        $this->attachAttribute($p, 'voltage', 'Напряжение', 20, '220v', null);

        $response = $this->getJson($this->endpoint());

        $section = collect($response->json('data'))->firstWhere('code', 'voltage');
        $this->assertNotNull($section);
        $this->assertSame('220v', $section['values'][0]['name']);
    }

    public function test_section_order_follows_attribute_position(): void
    {
        $p = $this->makeProduct();
        $this->attachAttribute($p, 'type', 'Тип', 30, 'switch', 'Выключатель', position: 2);
        $this->attachAttribute($p, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider', position: 0);
        $this->attachAttribute($p, 'voltage', 'Напряжение', 20, '220v', '220 В', position: 1);

        $response = $this->getJson($this->endpoint());

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame(['price', 'manufacturer', 'voltage', 'type'], $codes);
    }

    // ==================== Взаимозависимые фильтры (MID-304) ====================

    private function section(string $query, string $code): ?array
    {
        $response = $this->getJson($this->endpoint() . $query);
        $response->assertOk();

        return collect($response->json('data'))->firstWhere('code', $code);
    }

    /**
     * @return array<string, array{count: int, available: bool}>
     */
    private function valueStats(?array $section): array
    {
        $this->assertNotNull($section);

        return collect($section['values'])
            ->mapWithKeys(fn (array $value) => [$value['code'] => [
                'count' => $value['count'],
                'available' => $value['available'],
            ]])
            ->all();
    }

    public function test_values_have_product_count_and_availability(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();

        $this->attachAttribute($p1, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p2, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p3, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');

        $this->assertSame([
            'abb' => ['count' => 1, 'available' => true],
            'schneider' => ['count' => 2, 'available' => true],
        ], $this->valueStats($this->section('', 'manufacturer')));
    }

    public function test_selection_in_one_group_narrows_counts_in_other_groups(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();

        $this->attachAttribute($p1, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p1, 'voltage', 'Напряжение', 20, '220v', '220 В');
        $this->attachAttribute($p2, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');
        $this->attachAttribute($p2, 'voltage', 'Напряжение', 21, '380v', '380 В');
        $this->attachAttribute($p3, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p3, 'voltage', 'Напряжение', 21, '380v', '380 В');

        // Выбран 220 В: у ABB нет такого товара — значение остаётся в списке,
        // но недоступно; Schneider доступен с одним товаром.
        $this->assertSame([
            'abb' => ['count' => 0, 'available' => false],
            'schneider' => ['count' => 1, 'available' => true],
        ], $this->valueStats($this->section('?filter[voltage]=220v', 'manufacturer')));
    }

    public function test_own_group_selection_does_not_narrow_its_counts(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();

        $this->attachAttribute($p1, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p2, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');

        // Внутри группы OR: после выбора Schneider можно добавить ABB.
        $this->assertSame([
            'abb' => ['count' => 1, 'available' => true],
            'schneider' => ['count' => 1, 'available' => true],
        ], $this->valueStats($this->section('?filter[manufacturer]=schneider', 'manufacturer')));
    }

    public function test_price_range_narrows_attribute_counts(): void
    {
        $cheap = $this->makeProduct(100);
        $expensive = $this->makeProduct(900);

        $this->attachAttribute($cheap, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');
        $this->attachAttribute($expensive, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');

        $this->assertSame([
            'abb' => ['count' => 0, 'available' => false],
            'schneider' => ['count' => 1, 'available' => true],
        ], $this->valueStats($this->section('?priceMin=500&priceMax=1000', 'manufacturer')));
    }

    public function test_price_bounds_follow_other_filters_but_not_own_range(): void
    {
        $abb = $this->makeProduct(100);
        $schneiderCheap = $this->makeProduct(500);
        $schneiderExpensive = $this->makeProduct(900);

        $this->attachAttribute($abb, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');
        $this->attachAttribute($schneiderCheap, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($schneiderExpensive, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');

        // Выбранный диапазон цены не сжимает ползунок до самого себя,
        // а выбор производителя — сужает до его товаров.
        $price = $this->section('?filter[manufacturer]=schneider&priceMin=600', 'price');

        $this->assertSame(500, $price['min']);
        $this->assertSame(900, $price['max']);
    }

    public function test_value_count_matches_listing_count_only(): void
    {
        $p1 = $this->makeProduct(200);
        $p2 = $this->makeProduct(700);
        $p3 = $this->makeProduct(800);

        $this->attachAttribute($p1, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p2, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p3, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');

        $stats = $this->valueStats($this->section('?priceMin=500', 'manufacturer'));

        $total = $this->getJson(
            "/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products?priceMin=500&filter[manufacturer]=schneider&countOnly=1"
        )->assertOk()->json('total');

        $this->assertSame($total, $stats['schneider']['count']);
    }

    public function test_only_discounted_narrows_value_list(): void
    {
        $discounted = Product::factory()->for($this->store)->inCategory($this->category)
            ->create(['price' => 500, 'price_old' => 700]);
        $regular = $this->makeProduct();

        $this->attachAttribute($discounted, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($regular, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');

        $section = $this->section('?onlyDiscounted=1', 'manufacturer');

        $this->assertSame(['schneider'], collect($section['values'])->pluck('code')->all());
    }

    public function test_adult_products_are_hidden_from_guest_unless_show_adult(): void
    {
        $adult = Product::factory()->for($this->store)->inCategory($this->category)
            ->create(['price' => 500, 'adult_only' => true]);
        $regular = $this->makeProduct();

        $this->attachAttribute($adult, 'manufacturer', 'Производитель', 10, 'winery', 'Винодельня');
        $this->attachAttribute($regular, 'manufacturer', 'Производитель', 11, 'dairy', 'Молзавод');

        $hidden = $this->section('', 'manufacturer');
        $this->assertSame(['dairy'], collect($hidden['values'])->pluck('code')->all());

        $shown = $this->section('?showAdult=1', 'manufacturer');
        $this->assertEqualsCanonicalizing(['dairy', 'winery'], collect($shown['values'])->pluck('code')->all());
    }

    public function test_filters_by_slug_accept_selection(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();

        $this->attachAttribute($p1, 'manufacturer', 'Производитель', 10, 'schneider', 'Schneider');
        $this->attachAttribute($p1, 'voltage', 'Напряжение', 20, '220v', '220 В');
        $this->attachAttribute($p2, 'manufacturer', 'Производитель', 11, 'abb', 'ABB');

        $response = $this->getJson(
            "/api/v1/stores/{$this->store->id}/categories/slug/{$this->category->slug}/filters?filter[voltage]=220v"
        );

        $response->assertOk();
        $this->assertSame([
            'abb' => ['count' => 0, 'available' => false],
            'schneider' => ['count' => 1, 'available' => true],
        ], $this->valueStats(collect($response->json('data'))->firstWhere('code', 'manufacturer')));
    }
}
