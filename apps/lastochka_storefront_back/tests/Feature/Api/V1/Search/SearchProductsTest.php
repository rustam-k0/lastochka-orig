<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Models\Address;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchProductsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        // Отключаем Observer, т.к. в тестах мы индексируем вручную
        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);

        // Очищаем индекс перед каждым тестом
        $this->searchClient->resetIndex();
    }

    public function test_search_products_returns_grouped_by_stores(): void
    {
        $store1 = Store::factory()->create(['name' => 'Store 1']);
        $store2 = Store::factory()->create(['name' => 'Store 2']);
        $category = Category::factory()->forStore($store1)->create();

        $product1 = Product::factory()->for($store1)->inCategory($category)->create([
            'title' => 'Soup with meat',
        ]);
        $product2 = Product::factory()->for($store1)->inCategory($category)->create([
            'title' => 'Soup classic',
        ]);
        $product3 = Product::factory()->for($store2)->inCategory($category)->create([
            'title' => 'Soup vegetarian',
        ]);

        // Индексируем товары
        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);
        $this->searchService->indexProduct($product3);

        $response = $this->getJson('/api/v1/search/products?query=Soup');

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'total',
                'data' => [
                    '*' => [
                        'store' => ['id', 'name'],
                        'products' => [
                            '*' => ['id', 'title', 'price'],
                        ],
                        'totalInStore',
                    ],
                ],
            ])
            ->assertJsonPath('total', 2); // 2 магазина с результатами
    }

    public function test_search_products_sorted_by_products_count(): void
    {
        $store1 = Store::factory()->create(['name' => 'Store with 1 product']);
        $store2 = Store::factory()->create(['name' => 'Store with 3 products']);
        $category = Category::factory()->forStore($store1)->create();

        // 1 товар в store1
        $product1 = Product::factory()->for($store1)->inCategory($category)->create([
            'title' => 'Milk 3.2%',
        ]);

        // 3 товара в store2
        $product2 = Product::factory()->for($store2)->inCategory($category)->create([
            'title' => 'Milk 2.5%',
        ]);
        $product3 = Product::factory()->for($store2)->inCategory($category)->create([
            'title' => 'Milk baked',
        ]);
        $product4 = Product::factory()->for($store2)->inCategory($category)->create([
            'title' => 'Milk goat',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);
        $this->searchService->indexProduct($product3);
        $this->searchService->indexProduct($product4);

        $response = $this->getJson('/api/v1/search/products?query=Milk');

        $response->assertOk();

        $data = $response->json('data');

        // Магазин с большим количеством товаров должен быть первым
        $this->assertEquals($store2->id, $data[0]['store']['id']);
        $this->assertEquals(3, $data[0]['totalInStore']);
        $this->assertEquals($store1->id, $data[1]['store']['id']);
        $this->assertEquals(1, $data[1]['totalInStore']);
    }

    public function test_search_products_excludes_inactive(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $activeProduct = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Bread white',
        ]);
        $inactiveProduct = Product::factory()->inactive()->for($store)->inCategory($category)->create([
            'title' => 'Bread black',
        ]);

        $this->searchService->indexProduct($activeProduct);
        // Неактивный не индексируется

        $response = $this->getJson('/api/v1/search/products?query=Bread');

        $response->assertOk()
            ->assertJsonPath('total', 1);

        $productIds = collect($response->json('data.0.products'))->pluck('id')->all();
        $this->assertContains($activeProduct->id, $productIds);
        $this->assertNotContains($inactiveProduct->id, $productIds);
    }

    public function test_search_products_excludes_out_of_stock(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $inStockProduct = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Cheese parmesan',
            'quantity' => 10,
        ]);
        $outOfStockProduct = Product::factory()->outOfStock()->for($store)->inCategory($category)->create([
            'title' => 'Cheese gouda',
        ]);

        $this->searchService->indexProduct($inStockProduct);
        $this->searchService->indexProduct($outOfStockProduct);

        $response = $this->getJson('/api/v1/search/products?query=Cheese');

        $response->assertOk();

        $productIds = collect($response->json('data.0.products'))->pluck('id')->all();
        $this->assertContains($inStockProduct->id, $productIds);
        $this->assertNotContains($outOfStockProduct->id, $productIds);
    }

    public function test_search_products_pagination(): void
    {
        $stores = Store::factory()->count(5)->create();
        $category = Category::factory()->forStore($stores->first())->create();

        foreach ($stores as $store) {
            $product = Product::factory()->for($store)->inCategory($category)->create([
                'title' => 'Test product',
            ]);
            $this->searchService->indexProduct($product);
        }

        // Первая страница
        $response = $this->getJson('/api/v1/search/products?query=Test&perPage=2&page=1');

        $response->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(2, 'data');

        // Вторая страница
        $response = $this->getJson('/api/v1/search/products?query=Test&perPage=2&page=2');

        $response->assertOk()
            ->assertJsonPath('hasMore', true)
            ->assertJsonCount(2, 'data');

        // Последняя страница
        $response = $this->getJson('/api/v1/search/products?query=Test&perPage=2&page=3');

        $response->assertOk()
            ->assertJsonPath('hasMore', false)
            ->assertJsonCount(1, 'data');
    }

    public function test_search_products_limits_products_per_store(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        // Создаём 10 товаров
        for ($i = 1; $i <= 10; $i++) {
            $product = Product::factory()->for($store)->inCategory($category)->create([
                'title' => "Item {$i}",
            ]);
            $this->searchService->indexProduct($product);
        }

        $response = $this->getJson('/api/v1/search/products?query=Item&productsPerStore=3');

        $response->assertOk()
            ->assertJsonCount(3, 'data.0.products')
            ->assertJsonPath('data.0.totalInStore', 10);
    }

    public function test_search_products_requires_min_2_chars(): void
    {
        // Test with single ASCII char
        $response = $this->getJson('/api/v1/search/products?query=A');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }

    public function test_search_products_empty_result(): void
    {
        $response = $this->getJson('/api/v1/search/products?query=NonExistentProduct');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('hasMore', false)
            ->assertJsonPath('data', []);
    }

    public function test_search_by_sku(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Milk',
            'sku' => 'MLK-001-TEST',
        ]);

        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/products?query=MLK-001');

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_search_products_filter_by_brand(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $brand1 = Brand::factory()->create(['title' => 'ABB']);
        $brand2 = Brand::factory()->create(['title' => 'Schneider']);

        $product1 = Product::factory()->for($store)->inCategory($category)->create([
            'brand_id' => $brand1->id,
            'title' => 'Switch ABB 16A',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create([
            'brand_id' => $brand2->id,
            'title' => 'Switch Schneider 16A',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        // Фильтр по бренду ABB
        $response = $this->getJson("/api/v1/search/products?query=Switch&brand_id={$brand1->id}");

        $response->assertOk();
        $productIds = collect($response->json('data.0.products'))->pluck('id')->all();
        $this->assertContains($product1->id, $productIds);
        $this->assertNotContains($product2->id, $productIds);
    }

    public function test_search_products_filter_by_category(): void
    {
        $store = Store::factory()->create();
        $category1 = Category::factory()->forStore($store)->create(['name' => 'Автоматы']);
        $category2 = Category::factory()->forStore($store)->create(['name' => 'Розетки']);

        $product1 = Product::factory()->for($store)->inCategory($category1)->create([
            'title' => 'Test device A',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category2)->create([
            'title' => 'Test device B',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        // Фильтр по категории
        $response = $this->getJson("/api/v1/search/products?query=Test device&category_id={$category1->id}");

        $response->assertOk();
        $productIds = collect($response->json('data.0.products'))->pluck('id')->all();
        $this->assertContains($product1->id, $productIds);
        $this->assertNotContains($product2->id, $productIds);
    }

    public function test_search_products_without_filters_returns_all(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $brand = Brand::factory()->create();

        $product1 = Product::factory()->for($store)->inCategory($category)->create([
            'brand_id' => $brand->id,
            'title' => 'Cable item one',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create([
            'brand_id' => null,
            'title' => 'Cable item two',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        // Без фильтров — оба товара
        $response = $this->getJson('/api/v1/search/products?query=Cable item');

        $response->assertOk();
        $productIds = collect($response->json('data.0.products'))->pluck('id')->all();
        $this->assertContains($product1->id, $productIds);
        $this->assertContains($product2->id, $productIds);
    }

    public function test_search_products_response_includes_matching_categories(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        $chemistry = Category::factory()->forStore($store)->create(['name' => 'Бытовая химия']);

        $product = Product::factory()->for($store)->inCategory($milk)->create([
            'title' => 'Молоко 3.2%',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/products?query=моло');

        $response->assertOk()
            ->assertJsonStructure([
                'hasMore',
                'total',
                'data',
                'categories' => [['id', 'name', 'slug']],
            ]);

        $ids = array_column($response->json('categories'), 'id');
        $this->assertContains($milk->id, $ids);
        $this->assertNotContains($chemistry->id, $ids);

        // Товарная выдача не сломана
        $this->assertSame(1, $response->json('total'));
    }

    public function test_search_products_categories_present_when_no_product_hits(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        $response = $this->getJson('/api/v1/search/products?query=моло');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(1, 'categories')
            ->assertJsonPath('categories.0.id', $milk->id);
    }

    public function test_search_products_categories_limit_param(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко козье']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко овсяное']);

        $this->getJson('/api/v1/search/products?query=моло&categoriesLimit=2')
            ->assertOk()
            ->assertJsonCount(2, 'categories');

        $this->getJson('/api/v1/search/products?query=моло&categoriesLimit=0')
            ->assertOk()
            ->assertJsonCount(0, 'categories');

        // deprecated snake_case алиас
        $this->getJson('/api/v1/search/products?query=моло&categories_limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'categories');
    }

    public function test_search_products_categories_respect_available_stores_for_address(): void
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $availableStore = Store::factory()->create(['code' => '1', 'name' => 'Available']);
        $unavailableStore = Store::factory()->create(['code' => '2', 'name' => 'Unavailable']);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $reachable = Category::factory()->forStore($availableStore)->create(['name' => 'Молоко']);
        $unreachable = Category::factory()->forStore($unavailableStore)->create(['name' => 'Молоко фермерское']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/search/products?query=моло');

        $response->assertOk();

        $ids = array_column($response->json('categories'), 'id');
        $this->assertContains($reachable->id, $ids);
        $this->assertNotContains($unreachable->id, $ids);
    }
}
