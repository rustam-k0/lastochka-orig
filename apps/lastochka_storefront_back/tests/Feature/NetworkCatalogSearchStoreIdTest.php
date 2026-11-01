<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * MENDEV-13: глобальный поиск и рекомендации принимают storeId — так же,
 * как карточка, листинги, избранное и сравнение.
 */
class NetworkCatalogSearchStoreIdTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    private Store $anchorStore;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();

        $this->anchorStore = Store::factory()->create(['code' => 'anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'sid-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'sid-b', 'is_active' => true]);

        $this->setNetworkCatalog(true);
    }

    private function setNetworkCatalog(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }

    private function networkProduct(string $title): Product
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'is_active' => true,
            'title' => $title,
            'price' => 0,
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->searchService->indexProduct($product);

        return $product;
    }

    private function offer(Product $product, Store $store, float $price, int $quantity = 5): StoreProduct
    {
        return StoreProduct::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'price' => $price,
            'quantity' => $quantity,
        ]);
    }

    public function test_search_uses_store_id_price(): void
    {
        $product = $this->networkProduct('Коньяк сетевой');
        $this->offer($product, $this->storeA, 100.0);
        $this->offer($product, $this->storeB, 900.0);

        $this->getJson('/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeA->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.price', 100);

        $this->getJson('/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeB->id}")
            ->assertOk()
            ->assertJsonPath('data.0.price', 900);
    }

    public function test_search_with_store_id_hides_products_without_offer(): void
    {
        $withOffer = $this->networkProduct('Коньяк с оффером');
        $withoutOffer = $this->networkProduct('Коньяк без оффера');

        $this->offer($withOffer, $this->storeA, 100.0);
        $this->offer($withoutOffer, $this->storeB, 100.0);

        $response = $this->getJson(
            '/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeA->id}"
        )->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($withOffer->id, $ids);
        $this->assertNotContains($withoutOffer->id, $ids);
    }

    /**
     * Явный storeId приоритетнее магазина активного адреса — так же,
     * как в остальных каталожных ручках.
     */
    public function test_explicit_store_id_wins_over_active_address(): void
    {
        /** @var FakeSlotsApiClient $fakeSlotsApi */
        $fakeSlotsApi = $this->app->make(SlotsApiClientInterface::class);
        $fakeSlotsApi->setShops([
            ['id' => 1, 'code' => $this->storeA->code, 'delivery_price' => 100, 'min_order_price' => 500],
        ]);

        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 56.0124,
            'longitude' => 92.8553,
        ]);

        $product = $this->networkProduct('Коньяк выдержанный');
        $this->offer($product, $this->storeA, 100.0);
        $this->offer($product, $this->storeB, 900.0);

        // Без storeId — цена магазина адреса.
        $this->actingAs($user)
            ->getJson('/api/v1/search/products?query='.urlencode('Коньяк'))
            ->assertOk()
            ->assertJsonPath('data.0.price', 100);

        // С storeId — цена запрошенного магазина.
        $this->actingAs($user)
            ->getJson('/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeB->id}")
            ->assertOk()
            ->assertJsonPath('data.0.price', 900);
    }

    /**
     * Кеш поиска разведён по магазинам: иначе первый запрос отдавал бы свою
     * цену всем последующим.
     */
    public function test_search_cache_is_separated_by_store(): void
    {
        $product = $this->networkProduct('Коньяк кешированный');
        $this->offer($product, $this->storeA, 100.0);
        $this->offer($product, $this->storeB, 900.0);

        $first = $this->getJson('/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeA->id}")
            ->assertOk()
            ->json('data.0.price');
        $second = $this->getJson('/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeB->id}")
            ->assertOk()
            ->json('data.0.price');

        $this->assertNotEquals($first, $second);
    }

    public function test_invalid_store_id_is_rejected(): void
    {
        $this->getJson('/api/v1/search/products?query='.urlencode('Коньяк').'&storeId=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors('storeId');
    }

    /**
     * Адрес пользователя не покрывает ни один магазин, на фронте выбран
     * магазин storeA: с storeId выдача и категории должны быть по нему,
     * а не пустыми из-за адреса.
     */
    public function test_explicit_store_id_when_address_covers_no_store(): void
    {
        /** @var FakeSlotsApiClient $fakeSlotsApi */
        $fakeSlotsApi = $this->app->make(SlotsApiClientInterface::class);
        $fakeSlotsApi->setShops([]);

        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $category = Category::factory()->create(['name' => 'Коньяк', 'slug' => 'konyak']);
        $product = $this->networkProduct('Коньяк армянский');
        $this->offer($product, $this->storeA, 100.0);

        // Без storeId адрес без покрытия даёт пустую выдачу и пустые категории.
        $this->actingAs($user)
            ->getJson('/api/v1/search/products?query='.urlencode('Коньяк'))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'categories');

        // С storeId — товар и категория есть, адрес не учитывается.
        $response = $this->actingAs($user)
            ->getJson('/api/v1/search/products?query='.urlencode('Коньяк')."&storeId={$this->storeA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $product->id);

        $this->assertSame([$category->id], array_column($response->json('categories'), 'id'));
    }

    public function test_recommendations_with_store_id_when_address_covers_no_store(): void
    {
        /** @var FakeSlotsApiClient $fakeSlotsApi */
        $fakeSlotsApi = $this->app->make(SlotsApiClientInterface::class);
        $fakeSlotsApi->setShops([]);

        $user = User::factory()->create();
        Address::factory()->active()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $product = $this->networkProduct('Коньяк рекомендованный');
        $product->update(['on_search_page' => true]);
        $this->offer($product, $this->storeA, 100.0);

        $this->actingAs($user)
            ->getJson('/api/v1/search/recommendations')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($user)
            ->getJson("/api/v1/search/recommendations?storeId={$this->storeA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.price', 100);
    }

    public function test_recommendations_use_store_id_price(): void
    {
        $product = $this->networkProduct('Коньяк рекомендованный');
        $product->update(['on_search_page' => true]);

        $this->offer($product, $this->storeA, 100.0);
        $this->offer($product, $this->storeB, 900.0);

        $this->getJson("/api/v1/search/recommendations?storeId={$this->storeA->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.price', 100);

        $this->getJson("/api/v1/search/recommendations?storeId={$this->storeB->id}")
            ->assertOk()
            ->assertJsonPath('data.0.price', 900);
    }
}
