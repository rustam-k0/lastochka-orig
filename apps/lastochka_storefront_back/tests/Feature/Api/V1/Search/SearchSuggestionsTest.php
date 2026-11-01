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

final class SearchSuggestionsTest extends TestCase
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

    public function test_get_search_suggestions(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product1 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Soup with meat',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Soup ukrainian',
        ]);
        $product3 = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Soup vegetarian',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);
        $this->searchService->indexProduct($product3);

        $response = $this->getJson('/api/v1/search/suggestions?query=Soup');

        $response->assertOk();

        $suggestions = $response->json();
        $this->assertIsArray($suggestions);
        $this->assertCount(3, $suggestions);
        $this->assertContains('Soup with meat', $suggestions);
        $this->assertContains('Soup ukrainian', $suggestions);
        $this->assertContains('Soup vegetarian', $suggestions);
    }

    public function test_search_suggestions_returns_unique_titles(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();
        $category = Category::factory()->forStore($store1)->create();

        // Одинаковое название в разных магазинах
        $product1 = Product::factory()->for($store1)->inCategory($category)->create([
            'title' => 'Milk 3.2%',
        ]);
        $product2 = Product::factory()->for($store2)->inCategory($category)->create([
            'title' => 'Milk 3.2%',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        $response = $this->getJson('/api/v1/search/suggestions?query=Milk');

        $response->assertOk();

        $suggestions = $response->json();
        $this->assertCount(1, $suggestions);
        $this->assertEquals('Milk 3.2%', $suggestions[0]);
    }

    public function test_search_suggestions_respects_limit(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        // Создаём 15 товаров
        for ($i = 1; $i <= 15; $i++) {
            $product = Product::factory()->for($store)->inCategory($category)->create([
                'title' => "Test product {$i}",
            ]);
            $this->searchService->indexProduct($product);
        }

        $response = $this->getJson('/api/v1/search/suggestions?query=Test&limit=5');

        $response->assertOk();

        $suggestions = $response->json();
        $this->assertLessThanOrEqual(5, count($suggestions));
    }

    public function test_search_suggestions_min_1_char(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Apple',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=Ap');

        $response->assertOk();
    }

    public function test_search_suggestions_excludes_inactive(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $activeProduct = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Juice apple',
        ]);
        $inactiveProduct = Product::factory()->inactive()->for($store)->inCategory($category)->create([
            'title' => 'Juice orange',
        ]);

        $this->searchService->indexProduct($activeProduct);
        // Неактивный не индексируется

        $response = $this->getJson('/api/v1/search/suggestions?query=Juice');

        $response->assertOk();

        $suggestions = $response->json();
        $this->assertContains('Juice apple', $suggestions);
        $this->assertNotContains('Juice orange', $suggestions);
    }

    public function test_search_suggestions_empty_result(): void
    {
        $response = $this->getJson('/api/v1/search/suggestions?query=NonExistentProduct');

        $response->assertOk()
            ->assertJson([]);
    }

    public function test_search_suggestions_requires_query(): void
    {
        $response = $this->getJson('/api/v1/search/suggestions');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }

    public function test_search_suggestions_max_limit(): void
    {
        $response = $this->getJson('/api/v1/search/suggestions?query=test&limit=25');

        // Превышение лимита — ошибка валидации
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_structured_suggestions_returns_categories_and_brands(): void
    {
        $store = Store::factory()->create();
        $category1 = Category::factory()->forStore($store)->create(['name' => 'Circuit breakers']);
        $category2 = Category::factory()->forStore($store)->create(['name' => 'Control systems']);
        $brand = Brand::factory()->create(['title' => 'Arkin']);

        $product1 = Product::factory()->for($store)->inCategory($category1)->create([
            'brand_id' => $brand->id,
            'title' => 'Switch ABB 16A',
        ]);
        $product2 = Product::factory()->for($store)->inCategory($category2)->create([
            'brand_id' => $brand->id,
            'title' => 'Switch Arkin 25A',
        ]);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        $response = $this->getJson('/api/v1/search/suggestions?query=Switch&structured=1');

        $response->assertOk()
            ->assertJsonStructure([
                'categories' => [['id', 'name', 'slug']],
                'brands' => [['id', 'title']],
                'products',
            ]);

        $data = $response->json();

        // Категории из хитов
        $categoryIds = array_column($data['categories'], 'id');
        $this->assertContains($category1->id, $categoryIds);
        $this->assertContains($category2->id, $categoryIds);

        // Бренд из хитов
        $this->assertCount(1, $data['brands']);
        $this->assertEquals($brand->id, $data['brands'][0]['id']);
        $this->assertEquals('Arkin', $data['brands'][0]['title']);

        // Продукты — string[] как раньше
        $this->assertContains('Switch ABB 16A', $data['products']);
        $this->assertContains('Switch Arkin 25A', $data['products']);
    }

    public function test_structured_false_returns_flat_array(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'title' => 'Cable VVG 3x2',
        ]);
        $this->searchService->indexProduct($product);

        // Без structured — плоский массив (backwards compatible)
        $response = $this->getJson('/api/v1/search/suggestions?query=Cable');
        $response->assertOk();

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('categories', $data);
        $this->assertContains('Cable VVG 3x2', $data);

        // structured=0 — тоже плоский
        $response = $this->getJson('/api/v1/search/suggestions?query=Cable&structured=0');
        $response->assertOk();

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('categories', $data);
    }

    public function test_suggestions_filtered_by_active_address_available_stores(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create([
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

        $categoryAvailable = Category::factory()->forStore($availableStore)->create();
        $categoryUnavailable = Category::factory()->forStore($unavailableStore)->create();

        $available = Product::factory()->for($availableStore)->inCategory($categoryAvailable)->create([
            'title' => 'Switch 3P 32A',
        ]);
        $unavailable = Product::factory()->for($unavailableStore)->inCategory($categoryUnavailable)->create([
            'title' => 'Switch 3P 16A',
        ]);

        $this->searchService->indexProduct($available);
        $this->searchService->indexProduct($unavailable);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/search/suggestions?query=Switch');

        $response->assertOk();

        $suggestions = $response->json();
        $this->assertContains('Switch 3P 32A', $suggestions);
        $this->assertNotContains('Switch 3P 16A', $suggestions);
    }

    public function test_structured_suggestions_without_brands(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create(['name' => 'Sockets']);

        $product = Product::factory()->for($store)->inCategory($category)->create([
            'brand_id' => null,
            'title' => 'Socket double wall',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=Socket&structured=1');

        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data['categories']);
        $this->assertEmpty($data['brands']);
        $this->assertContains('Socket double wall', $data['products']);
    }

    public function test_structured_suggestions_name_matched_categories_first(): void
    {
        $store = Store::factory()->create();
        // Матчится по названию, товаров-хитов в ней нет
        $nameCat = Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        // Название не матчится, но категория у товара-хита
        $hitCat = Category::factory()->forStore($store)->create(['name' => 'Бытовая техника']);

        $product = Product::factory()->for($store)->inCategory($hitCat)->create([
            'title' => 'Вспениватель молока Polaris',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1');

        $response->assertOk();

        $ids = array_column($response->json('categories'), 'id');

        // name-match первым, категория из товарных хитов — добивкой
        $this->assertSame($nameCat->id, $ids[0]);
        $this->assertContains($hitCat->id, $ids);
    }

    public function test_structured_suggestions_categories_dedup(): void
    {
        $store = Store::factory()->create();
        $milk = Category::factory()->forStore($store)->create(['name' => 'Молоко']);

        // Товар-хит лежит в той же name-match категории
        $product = Product::factory()->for($store)->inCategory($milk)->create([
            'title' => 'Молоко 3.2%',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1');

        $response->assertOk();

        $ids = array_column($response->json('categories'), 'id');

        $this->assertSame([$milk->id], $ids);
    }

    public function test_structured_suggestions_categories_capped_by_limit(): void
    {
        $store = Store::factory()->create();
        Category::factory()->forStore($store)->create(['name' => 'Молоко']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко козье']);
        Category::factory()->forStore($store)->create(['name' => 'Молоко овсяное']);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1&limit=2');

        $response->assertOk()
            ->assertJsonCount(2, 'categories');
    }

    public function test_structured_suggestions_categories_include_slug(): void
    {
        $store = Store::factory()->create();
        // name-match
        $nameCat = Category::factory()->forStore($store)->create(['name' => 'Молоко', 'slug' => 'moloko']);
        // добивка из хитов
        $hitCat = Category::factory()->forStore($store)->create(['name' => 'Бытовая техника', 'slug' => 'bytovaya-tehnika']);

        $product = Product::factory()->for($store)->inCategory($hitCat)->create([
            'title' => 'Вспениватель молока Polaris',
        ]);
        $this->searchService->indexProduct($product);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1');

        $response->assertOk();

        $categories = collect($response->json('categories'))->keyBy('id');

        $this->assertSame(
            ['id' => $nameCat->id, 'name' => 'Молоко', 'slug' => 'moloko'],
            $categories->get($nameCat->id),
        );
        $this->assertSame(
            ['id' => $hitCat->id, 'name' => 'Бытовая техника', 'slug' => 'bytovaya-tehnika'],
            $categories->get($hitCat->id),
        );
    }

    public function test_suggestions_store_id_overrides_active_address(): void
    {
        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $addressStore = Store::factory()->create(['code' => '1', 'name' => 'Address store']);
        $selectedStore = Store::factory()->create(['code' => '2', 'name' => 'Selected store']);

        // По адресу доставляет только первый магазин; на фронте выбран второй
        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => '1', 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        $addressCat = Category::factory()->forStore($addressStore)->create(['name' => 'Молоко фермерское']);
        $selectedCat = Category::factory()->forStore($selectedStore)->create(['name' => 'Молоко козье']);

        $addressProduct = Product::factory()->for($addressStore)->inCategory($addressCat)->create([
            'title' => 'Молоко 3.2%',
        ]);
        $selectedProduct = Product::factory()->for($selectedStore)->inCategory($selectedCat)->create([
            'title' => 'Молоко козье 1л',
        ]);

        $this->searchService->indexProduct($addressProduct);
        $this->searchService->indexProduct($selectedProduct);

        // Без storeId — по адресу: категории и товары только первого магазина
        $byAddress = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/search/suggestions?query=моло&structured=1');

        $byAddress->assertOk();
        $this->assertSame([$addressCat->id], array_column($byAddress->json('categories'), 'id'));
        $this->assertSame(['Молоко 3.2%'], $byAddress->json('products'));

        // С storeId — по выбранному магазину, адрес не учитывается
        $byStore = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/search/suggestions?query=моло&structured=1&storeId=' . $selectedStore->id);

        $byStore->assertOk();
        $this->assertSame([$selectedCat->id], array_column($byStore->json('categories'), 'id'));
        $this->assertSame(['Молоко козье 1л'], $byStore->json('products'));
    }

    public function test_suggestions_store_id_for_guest(): void
    {
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $cat1 = Category::factory()->forStore($store1)->create(['name' => 'Молоко']);
        $cat2 = Category::factory()->forStore($store2)->create(['name' => 'Молоко козье']);

        $product1 = Product::factory()->for($store1)->inCategory($cat1)->create(['title' => 'Молоко 3.2%']);
        $product2 = Product::factory()->for($store2)->inCategory($cat2)->create(['title' => 'Молоко козье 1л']);

        $this->searchService->indexProduct($product1);
        $this->searchService->indexProduct($product2);

        $response = $this->getJson('/api/v1/search/suggestions?query=моло&structured=1&storeId=' . $store2->id);

        $response->assertOk();
        $this->assertSame([$cat2->id], array_column($response->json('categories'), 'id'));
        $this->assertSame(['Молоко козье 1л'], $response->json('products'));
    }

    public function test_suggestions_store_id_validation(): void
    {
        $this->getJson('/api/v1/search/suggestions?query=test&storeId=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['storeId']);

        $this->getJson('/api/v1/search/suggestions?query=test&storeId=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['storeId']);
    }
}
