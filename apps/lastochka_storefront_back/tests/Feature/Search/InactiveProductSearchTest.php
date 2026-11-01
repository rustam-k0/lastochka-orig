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
 * Объединение стоп-листов с полями карточки (Platrum 12188): видимость в
 * поиске определяет ТОЛЬКО is_active; поля stop_list_hidden больше нет
 * ни в документе, ни в visibilityFilters.
 */
final class InactiveProductSearchTest extends TestCase
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

    public function test_document_omits_stop_list_hidden_field(): void
    {
        $store = Store::factory()->create();
        $product = Product::factory()->for($store)->create([
            'quantity' => 10,
            'quantity_step' => 1,
        ]);

        $this->searchService->indexProduct($product);

        $doc = $this->searchClient->indexedDocument($product->id);

        $this->assertNotNull($doc);
        $this->assertArrayNotHasKey('stop_list_hidden', $doc);
        $this->assertArrayHasKey('is_active', $doc);
    }

    public function test_search_hides_inactive_product(): void
    {
        $store = Store::factory()->create();

        $inactive = Product::factory()->for($store)->create([
            'title' => 'Пельмени Скрытые',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => false,
        ]);

        $this->searchService->indexProduct($inactive);

        $query = urlencode('Пельмени');
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query={$query}");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_returns_visible_product(): void
    {
        $store = Store::factory()->create();

        $visible = Product::factory()->for($store)->create([
            'title' => 'Пельмени Видимые',
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ]);

        $this->searchService->indexProduct($visible);

        $query = urlencode('Пельмени');
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query={$query}");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }
}
