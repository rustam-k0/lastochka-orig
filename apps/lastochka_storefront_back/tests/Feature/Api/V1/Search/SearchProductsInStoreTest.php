<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchProductsInStoreTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        // Отключаем Observer
        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);

        // Очищаем индекс перед каждым тестом
        $this->searchClient->resetIndex();
    }

    public function test_search_products_in_store(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product1 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Kefir 1%',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Kefir 2.5%',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Kefir");

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'total',
                'data' => [
                    '*' => ['id', 'title', 'price'],
                ],
            ])
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_search_products_only_from_specified_store(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();
        $category = Category::factory()->forStore($store1)->create();

        $product1 = Product::factory()->for($store1)->inCategory($category)->create([
            'title' => 'Yogurt strawberry',
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($category)->create([
            'title' => 'Yogurt vanilla',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        // Поиск в store1
        $response = $this->getJson("/api/v1/search/stores/{$store1->id}/products?query=Yogurt");

        $response->assertOk()
            ->assertJsonPath('total', 1);

        $productIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($product1->id, $productIds);
        $this->assertNotContains($product2->id, $productIds);
    }

    public function test_search_products_in_store_pagination(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        // Создаём 15 товаров
        for ($i = 1; $i <= 15; $i++) {
            $product = Product::factory()->for($store)->inCategory($category)->create([
                'title' => "Dumpling variant {$i}",
            ]);
            $this->searchService->indexProduct($product);
        }

        // Первая страница
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Dumpling&perPage=5&page=1");

        $response->assertOk()
            ->assertJsonPath('total', 15)
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(5, 'data');

        // Последняя страница
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Dumpling&perPage=5&page=3");

        $response->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(5, 'data');
    }

    public function test_search_products_in_store_excludes_inactive(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $activeProduct = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Sour cream 15%',
        ]);
        $inactiveProduct = Product::factory()->inactive()->for($store)->inCategory($category)->create([
            'title' => 'Sour cream 20%',
        ]);

        $this->searchService->indexProduct($activeProduct);
        // Неактивный не индексируется

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Sour");

        $response->assertOk()
            ->assertJsonPath('total', 1);

        $productIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($activeProduct->id, $productIds);
        $this->assertNotContains($inactiveProduct->id, $productIds);
    }

    public function test_search_products_in_store_not_found(): void
    {
        $response = $this->getJson('/api/v1/search/stores/999/products?query=test');

        $response->assertNotFound();
    }

    public function test_search_products_in_store_requires_min_2_chars(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=A");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }

    public function test_search_products_in_store_empty_result(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=NonExistentProduct");

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('hasMore', false)
            ->assertJsonPath('data', []);
    }

    public function test_search_products_in_store_max_per_page(): void
    {
        $store = Store::factory()->create();

        // Запрос с perPage больше максимума — должна быть ошибка валидации
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=test&perPage=150");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['perPage']);
    }

    public function test_categories_facet_returns_counts_sorted_by_count(): void
    {
        $store = Store::factory()->create();
        $bakery = Category::factory()->forStore($store)->create(['name' => 'Bakery']);
        $bread = Category::factory()->forStore($store)->create(['name' => 'Bread']);

        foreach (['Bun sweet', 'Bun poppy', 'Bun cinnamon'] as $title) {
            $this->searchService->indexProduct(
                Product::factory()->for($store)->inCategory($bakery)->create(['title' => $title])
            );
        }

        $this->searchService->indexProduct(
            Product::factory()->for($store)->inCategory($bread)->create(['title' => 'Bun rye'])
        );

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Bun");

        $response->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('categories', [
                ['id' => $bakery->id, 'name' => 'Bakery', 'count' => 3],
                ['id' => $bread->id, 'name' => 'Bread', 'count' => 1],
            ]);
    }

    public function test_categories_facet_deduplicates_product_in_two_categories(): void
    {
        $store = Store::factory()->create();
        $first = Category::factory()->forStore($store)->create(['name' => 'Alpha']);
        $second = Category::factory()->forStore($store)->create(['name' => 'Beta']);

        $product = Product::factory()->for($store)->inCategory($first)->create(['title' => 'Croissant butter']);
        $product->categories()->syncWithoutDetaching([$second->id => ['position' => 0]]);

        $this->searchService->indexProduct($product->fresh());

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Croissant");

        // Товар один, но лежит в двух категориях — обе получают count = 1.
        // При равном count порядок по названию.
        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('categories', [
                ['id' => $first->id, 'name' => 'Alpha', 'count' => 1],
                ['id' => $second->id, 'name' => 'Beta', 'count' => 1],
            ]);
    }

    public function test_categories_facet_excludes_category_of_another_store(): void
    {
        $store = Store::factory()->create();
        $otherStore = Store::factory()->create();

        $own = Category::factory()->forStore($store)->create(['name' => 'Own']);
        $foreign = Category::factory()->forStore($otherStore)->create(['name' => 'Foreign']);

        $product = Product::factory()->for($store)->inCategory($own)->create(['title' => 'Pretzel salt']);
        $product->categories()->syncWithoutDetaching([$foreign->id => ['position' => 0]]);

        $this->searchService->indexProduct($product->fresh());

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Pretzel");

        $response->assertOk()
            ->assertJsonPath('categories', [
                ['id' => $own->id, 'name' => 'Own', 'count' => 1],
            ]);
    }

    public function test_categories_facet_excludes_inactive_category(): void
    {
        $store = Store::factory()->create();
        $active = Category::factory()->forStore($store)->create(['name' => 'Visible']);
        $inactive = Category::factory()->inactive()->forStore($store)->create(['name' => 'Hidden']);

        $product = Product::factory()->for($store)->inCategory($active)->create(['title' => 'Bagel plain']);
        $product->categories()->syncWithoutDetaching([$inactive->id => ['position' => 0]]);

        $this->searchService->indexProduct($product->fresh());

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Bagel");

        $response->assertOk()
            ->assertJsonPath('categories', [
                ['id' => $active->id, 'name' => 'Visible', 'count' => 1],
            ]);
    }

    public function test_categories_facet_excludes_category_disabled_in_store(): void
    {
        $store = Store::factory()->create();
        $enabled = Category::factory()->forStore($store)->create(['name' => 'Enabled']);
        $disabled = Category::factory()->forStore($store, ['is_active' => false])->create(['name' => 'Disabled']);

        $product = Product::factory()->for($store)->inCategory($enabled)->create(['title' => 'Focaccia herb']);
        $product->categories()->syncWithoutDetaching([$disabled->id => ['position' => 0]]);

        $this->searchService->indexProduct($product->fresh());

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Focaccia");

        $response->assertOk()
            ->assertJsonPath('categories', [
                ['id' => $enabled->id, 'name' => 'Enabled', 'count' => 1],
            ]);
    }

    public function test_categories_facet_ignores_products_out_of_stock_in_database(): void
    {
        $store = Store::factory()->create();
        $inStockCategory = Category::factory()->forStore($store)->create(['name' => 'InStock']);
        $staleCategory = Category::factory()->forStore($store)->create(['name' => 'Stale']);

        $this->searchService->indexProduct(
            Product::factory()->for($store)->inCategory($inStockCategory)->create(['title' => 'Ciabatta olive'])
        );

        // Индексируем в наличии, затем обнуляем остаток в БД без переиндексации:
        // ровно тот случай устаревшего документа, ради которого есть validProductIds().
        $stale = Product::factory()->for($store)->inCategory($staleCategory)->create(['title' => 'Ciabatta plain']);
        $this->searchService->indexProduct($stale);
        $stale->update(['quantity' => 0]);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Ciabatta");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('categories', [
                ['id' => $inStockCategory->id, 'name' => 'InStock', 'count' => 1],
            ]);
    }

    public function test_categories_facet_ignores_applied_category_filter(): void
    {
        $store = Store::factory()->create();
        $bakery = Category::factory()->forStore($store)->create(['name' => 'Bakery']);
        $bread = Category::factory()->forStore($store)->create(['name' => 'Bread']);

        foreach (['Roll sweet', 'Roll poppy'] as $title) {
            $this->searchService->indexProduct(
                Product::factory()->for($store)->inCategory($bakery)->create(['title' => $title])
            );
        }

        $this->searchService->indexProduct(
            Product::factory()->for($store)->inCategory($bread)->create(['title' => 'Roll rye'])
        );

        $response = $this->getJson(
            "/api/v1/search/stores/{$store->id}/products?query=Roll&categoryId={$bread->id}"
        );

        // Товарная выдача сузилась до выбранной категории, чипы остались полными.
        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('categories', [
                ['id' => $bakery->id, 'name' => 'Bakery', 'count' => 2],
                ['id' => $bread->id, 'name' => 'Bread', 'count' => 1],
            ]);
    }

    public function test_categories_facet_keeps_other_filters_while_ignoring_category(): void
    {
        $store = Store::factory()->create();
        $discounted = Category::factory()->forStore($store)->create(['name' => 'Discounted']);
        $regular = Category::factory()->forStore($store)->create(['name' => 'Regular']);

        $this->searchService->indexProduct(
            Product::factory()->withDiscount()->for($store)->inCategory($discounted)->create(['title' => 'Muffin berry'])
        );
        $this->searchService->indexProduct(
            Product::factory()->for($store)->inCategory($regular)->create(['title' => 'Muffin plain'])
        );

        $response = $this->getJson(
            "/api/v1/search/stores/{$store->id}/products?query=Muffin&onlyDiscounted=1&categoryId={$discounted->id}"
        );

        // categoryId игнорируется, onlyDiscounted — нет.
        $response->assertOk()
            ->assertJsonPath('categories', [
                ['id' => $discounted->id, 'name' => 'Discounted', 'count' => 1],
            ]);
    }

    public function test_categories_facet_identical_on_every_page(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Pies']);

        for ($i = 1; $i <= 6; $i++) {
            $this->searchService->indexProduct(
                Product::factory()->for($store)->inCategory($category)->create(['title' => "Pie variant {$i}"])
            );
        }

        $expected = [['id' => $category->id, 'name' => 'Pies', 'count' => 6]];

        $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Pie&perPage=2&page=1")
            ->assertOk()
            ->assertJsonPath('categories', $expected);

        $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Pie&perPage=2&page=3")
            ->assertOk()
            ->assertJsonPath('categories', $expected);
    }

    public function test_categories_facet_respects_limit(): void
    {
        config(['meilisearch.search.store_categories_limit' => 2]);

        $store = Store::factory()->create();

        // Три категории с разным числом товаров: в срез попадают две самые крупные.
        $counts = ['Big' => 3, 'Medium' => 2, 'Small' => 1];
        $categories = [];

        foreach ($counts as $name => $count) {
            $category = Category::factory()->forStore($store)->create(['name' => $name]);
            $categories[$name] = $category;

            for ($i = 1; $i <= $count; $i++) {
                $this->searchService->indexProduct(
                    Product::factory()->for($store)->inCategory($category)->create(['title' => "Tart {$name} {$i}"])
                );
            }
        }

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Tart");

        $response->assertOk()
            ->assertJsonPath('categories', [
                ['id' => $categories['Big']->id, 'name' => 'Big', 'count' => 3],
                ['id' => $categories['Medium']->id, 'name' => 'Medium', 'count' => 2],
            ]);
    }

    public function test_categories_facet_empty_when_nothing_found(): void
    {
        $store = Store::factory()->create();

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=NonExistentProduct");

        $response->assertOk()
            ->assertJsonPath('categories', []);
    }

    // ==================== Фильтры и сортировка (MID-304) ====================

    private function indexedProduct(Store $store, Category $category, string $title, int $price, ?array $manufacturer = null): Product
    {
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => $title,
            'price' => $price,
        ]);

        if ($manufacturer !== null) {
            [$valueId, $code, $name] = $manufacturer;

            \App\Models\ProductAttribute::create([
                'product_id' => $product->id,
                'code' => 'manufacturer',
                'name' => 'Производитель',
                'value' => $name,
                'attribute_value_id' => $valueId,
                'attribute_value_code' => $code,
                'position' => 0,
            ]);
        }

        $this->searchService->indexProduct($product);

        return $product;
    }

    public function test_attribute_filter_narrows_results_and_total(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $schneider = $this->indexedProduct($store, $category, 'Socket white', 300, [10, 'schneider', 'Schneider']);
        $this->indexedProduct($store, $category, 'Socket black', 400, [11, 'abb', 'ABB']);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Socket&filter[manufacturer]=schneider");

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([$schneider->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_price_range_narrows_results(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $this->indexedProduct($store, $category, 'Cheese soft', 100);
        $middle = $this->indexedProduct($store, $category, 'Cheese hard', 500);
        $this->indexedProduct($store, $category, 'Cheese blue', 900);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Cheese&priceMin=200&priceMax=600");

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([$middle->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_count_only_returns_total_without_data(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $this->indexedProduct($store, $category, 'Juice apple', 100, [10, 'rich', 'Rich']);
        $this->indexedProduct($store, $category, 'Juice orange', 150, [10, 'rich', 'Rich']);
        $this->indexedProduct($store, $category, 'Juice cherry', 200, [11, 'dobry', 'Добрый']);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Juice&filter[manufacturer]=rich&countOnly=true");

        $response->assertOk();
        $this->assertSame(['total' => 2], $response->json());
    }

    public function test_sort_by_name_orders_by_title(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $this->indexedProduct($store, $category, 'Tea green', 100);
        $this->indexedProduct($store, $category, 'Tea black', 200);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Tea&sort=name");

        $response->assertOk();
        $this->assertSame(['Tea black', 'Tea green'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_sort_by_relevance_keeps_search_engine_order(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        // FakeSearchClient отдаёт хиты в порядке индексации — это и есть
        // «релевантность» для теста.
        $this->indexedProduct($store, $category, 'Tea green', 100);
        $this->indexedProduct($store, $category, 'Tea black', 200);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Tea&sort=relevance");

        $response->assertOk();
        $this->assertSame(['Tea green', 'Tea black'], collect($response->json('data'))->pluck('title')->all());
    }

    public function test_category_chips_respect_attribute_filter(): void
    {
        $store = Store::factory()->create();
        $dairy = Category::factory()->forStore($store)->create(['name' => 'Dairy']);
        $bakery = Category::factory()->forStore($store)->create(['name' => 'Bakery']);

        $this->indexedProduct($store, $dairy, 'Farm butter', 300, [10, 'farm', 'Ферма']);
        $this->indexedProduct($store, $bakery, 'Farm bread', 100, [11, 'plant', 'Комбинат']);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query=Farm&filter[manufacturer]=farm");

        $response->assertOk()
            ->assertJsonPath('categories', [
                ['id' => $dairy->id, 'name' => 'Dairy', 'count' => 1],
            ]);
    }

    public function test_filters_endpoint_builds_sections_from_found_products(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $this->indexedProduct($store, $category, 'Kefir light', 100, [11, 'abb', 'ABB']);
        $this->indexedProduct($store, $category, 'Kefir classic', 500, [10, 'schneider', 'Schneider']);
        // Не находится по запросу — не должен влиять ни на цену, ни на значения.
        $this->indexedProduct($store, $category, 'Milk', 900, [12, 'legrand', 'Legrand']);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/filters?query=Kefir");

        $response->assertOk();
        $sections = collect($response->json('data'));

        $this->assertSame(['code' => 'price', 'name' => 'Цена', 'type' => 'range', 'min' => 100, 'max' => 500], $sections->firstWhere('code', 'price'));
        $this->assertSame(
            [
                ['id' => 11, 'code' => 'abb', 'name' => 'ABB', 'count' => 1, 'available' => true],
                ['id' => 10, 'code' => 'schneider', 'name' => 'Schneider', 'count' => 1, 'available' => true],
            ],
            $sections->firstWhere('code', 'manufacturer')['values']
        );
    }

    public function test_filters_endpoint_is_interdependent(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $this->indexedProduct($store, $category, 'Kefir light', 100, [11, 'abb', 'ABB']);
        $this->indexedProduct($store, $category, 'Kefir classic', 500, [10, 'schneider', 'Schneider']);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/filters?query=Kefir&filter[manufacturer]=abb&priceMin=300");

        $response->assertOk();
        $sections = collect($response->json('data'));

        // Цена считается с выбранным производителем, но без своего диапазона.
        $price = $sections->firstWhere('code', 'price');
        $this->assertSame(100, $price['min']);
        $this->assertSame(100, $price['max']);

        // Производитель — с ценой от 300, но без своего выбора.
        $counts = collect($sections->firstWhere('code', 'manufacturer')['values'])->pluck('count', 'code')->all();
        $this->assertSame(['abb' => 0, 'schneider' => 1], $counts);
    }

    public function test_filters_endpoint_respects_category_id(): void
    {
        $store = Store::factory()->create();
        $dairy = Category::factory()->forStore($store)->create();
        $bakery = Category::factory()->forStore($store)->create();

        $this->indexedProduct($store, $dairy, 'Farm butter', 300, [10, 'farm', 'Ферма']);
        $this->indexedProduct($store, $bakery, 'Farm bread', 100, [11, 'plant', 'Комбинат']);

        $response = $this->getJson("/api/v1/search/stores/{$store->id}/filters?query=Farm&categoryId={$dairy->id}");

        $response->assertOk();
        $manufacturer = collect($response->json('data'))->firstWhere('code', 'manufacturer');
        $this->assertSame(['farm'], collect($manufacturer['values'])->pluck('code')->all());
    }

    public function test_filters_endpoint_requires_query(): void
    {
        $store = Store::factory()->create();

        $this->getJson("/api/v1/search/stores/{$store->id}/filters")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }
}
