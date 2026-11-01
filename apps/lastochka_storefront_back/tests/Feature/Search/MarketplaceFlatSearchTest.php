<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Скрытый маркетплейс: GET /v1/search/products в marketplace режиме отдаёт
 * плоский список товаров без группировки по магазинам и без store.name
 * (партнёр-мерчант не должен быть виден клиенту). В standard режиме shape
 * не меняется — группировка по магазинам как раньше.
 */
final class MarketplaceFlatSearchTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    protected function setUp(): void
    {
        parent::setUp();

        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();
    }

    public function test_marketplace_mode_returns_flat_product_list_without_store(): void
    {
        $this->enableMarketplaceMode();

        $storeA = Store::factory()->create();
        $storeB = Store::factory()->create();

        $productA = Product::factory()->for($storeA)->create([
            'title' => 'Кабель силовой',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ]);
        $productB = Product::factory()->for($storeB)->create([
            'title' => 'Кабель медный',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ]);

        $this->searchService->indexProduct($productA);
        $this->searchService->indexProduct($productB);

        $query = urlencode('Кабель');
        $response = $this->getJson("/api/v1/search/products?query={$query}");

        $response->assertOk();

        // Плоский список: элементы data — товары, общий total по товарам.
        $this->assertTrue(array_is_list($response->json('data')), 'data должен быть JSON-массивом, не объектом с id-ключами');
        $this->assertSame(2, $response->json('total'));
        $this->assertFalse($response->json('hasMore'));
        $this->assertEqualsCanonicalizing(
            [$productA->id, $productB->id],
            $response->json('data.*.id'),
        );

        // Ни группировки по магазинам, ни утечки партнёра.
        foreach ($response->json('data') as $item) {
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayNotHasKey('store', $item);
            $this->assertArrayNotHasKey('products', $item);
            $this->assertArrayNotHasKey('totalInStore', $item);
        }
        $this->assertStringNotContainsString($storeA->name, $response->getContent());
        $this->assertStringNotContainsString($storeB->name, $response->getContent());

        // Секция categories остаётся в ответе.
        $this->assertIsArray($response->json('categories'));
    }

    public function test_marketplace_mode_paginates_by_products(): void
    {
        $this->enableMarketplaceMode();

        $store = Store::factory()->create();
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::factory()->for($store)->create([
                'title' => "Розетка накладная {$i}",
                'quantity' => 10,
                'quantity_step' => 1,
                'is_active' => true,
            ]);
            $this->searchService->indexProduct($product);
        }

        $query = urlencode('Розетка');

        $firstPage = $this->getJson("/api/v1/search/products?query={$query}&perPage=2&page=1");
        $firstPage->assertOk();
        $this->assertSame(3, $firstPage->json('total'));
        $this->assertCount(2, $firstPage->json('data'));
        $this->assertTrue($firstPage->json('hasMore'));

        $secondPage = $this->getJson("/api/v1/search/products?query={$query}&perPage=2&page=2");
        $secondPage->assertOk();
        $this->assertSame(3, $secondPage->json('total'));
        $this->assertCount(1, $secondPage->json('data'));
        $this->assertFalse($secondPage->json('hasMore'));
    }

    public function test_standard_mode_keeps_store_grouping(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'title' => 'Кабель силовой',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ]);

        $this->searchService->indexProduct($product);

        $query = urlencode('Кабель');
        $response = $this->getJson("/api/v1/search/products?query={$query}");

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertArrayHasKey('store', $response->json('data.0'));
        $this->assertArrayHasKey('products', $response->json('data.0'));
        $this->assertArrayHasKey('totalInStore', $response->json('data.0'));
    }

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'marketplace_mode' => ['type' => 'select', 'value' => 'marketplace'],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ],
        );
        app(ModuleConfigService::class)->invalidateCache();
    }
}
