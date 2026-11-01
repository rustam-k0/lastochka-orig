<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Документы, записанные в индекс до появления поля adult_only, не содержат
 * его вовсе (после деплоя фичи 18+ search:reindex на проде не запускался).
 * Фильтр видимости обязан такие документы пропускать: иначе товар виден
 * в каталоге, но пропадает из поиска — прод Ласточки 2026-08-26, 1312 товаров
 * в наличии выпали из выдачи.
 */
final class AdultOnlyMissingFieldSearchTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $searchClient;

    private SearchService $searchService;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Product::unsetEventDispatcher();

        $this->searchClient = $this->app->make(SearchClientInterface::class);
        $this->searchService = $this->app->make(SearchService::class);
        $this->searchClient->resetIndex();

        $this->store = Store::factory()->create();
    }

    /** Записывает документ так, как он лежал в индексе до фичи 18+. */
    private function indexWithoutAdultOnlyField(Product $product): void
    {
        $this->searchService->indexProduct($product);

        $doc = $this->searchClient->indexedDocument($product->id);
        $this->assertArrayHasKey('adult_only', $doc);

        unset($doc['adult_only']);
        $this->searchClient->indexDocument($doc);

        $this->assertArrayNotHasKey('adult_only', $this->searchClient->indexedDocument($product->id));
    }

    private function storeSearch(string $query): string
    {
        return "/api/v1/search/stores/{$this->store->id}/products?query=".urlencode($query);
    }

    public function test_store_search_returns_product_indexed_without_adult_only_field(): void
    {
        $product = Product::factory()->for($this->store)->create([
            'title' => 'Креветки Вичи 41/50 очищ',
            'quantity' => 4000,
            'quantity_step' => 200,
            'adult_only' => false,
        ]);

        $this->indexWithoutAdultOnlyField($product);

        $response = $this->getJson($this->storeSearch('креветки'));

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertSame([$product->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_multi_store_search_returns_product_indexed_without_adult_only_field(): void
    {
        $product = Product::factory()->for($this->store)->create([
            'title' => 'Креветки Вичи Королевские 50/70',
            'quantity' => 5000,
            'quantity_step' => 200,
            'adult_only' => false,
        ]);

        $this->indexWithoutAdultOnlyField($product);

        $response = $this->getJson('/api/v1/search/products?query='.urlencode('креветки'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('products')->flatten(1)->pluck('id')->all();
        $this->assertContains($product->id, $ids);
    }

    public function test_adult_product_is_still_hidden_from_guest(): void
    {
        $adult = Product::factory()->for($this->store)->create([
            'title' => 'Креветки в вине',
            'quantity' => 10,
            'quantity_step' => 1,
            'adult_only' => true,
        ]);

        $this->searchService->indexProduct($adult);

        $this->getJson($this->storeSearch('креветки'))
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson($this->storeSearch('креветки').'&showAdult=1')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
