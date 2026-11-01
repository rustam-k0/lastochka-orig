<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Счётчик результатов («N результатов» / totalInStore) обязан считаться по тому же
 * фильтру остатка, что и показанный список. Meili-индекс может быть устаревшим
 * (in_stock=true при реальном quantity=0) → раньше товар попадал в счётчик, но не
 * в выдачу: «2 результата», а карточка одна.
 */
final class SearchCountConsistencyTest extends TestCase
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

    public function test_total_in_store_counts_only_in_stock_products(): void
    {
        $store = Store::factory()->create();

        $inStock = Product::factory()->for($store)->create([
            'title' => 'KefirTest A',
            'quantity' => 5,
            'quantity_step' => 1,
        ]);
        $stale = Product::factory()->for($store)->create([
            'title' => 'KefirTest B',
            'quantity' => 5,
            'quantity_step' => 1,
        ]);

        // Индексируем оба «в наличии» → в Meili (fake) у обоих in_stock=true.
        $this->searchService->indexProduct($inStock);
        $this->searchService->indexProduct($stale);

        // Остаток B ушёл в 0 БЕЗ переиндексации (observer отключён) — Meili-doc
        // остаётся in_stock=true, БД = 0.
        $stale->update(['quantity' => 0]);

        $result = $this->searchService->search('KefirTest', 1, 10, 10);

        $storeData = $result['stores']->firstWhere('store.id', $store->id);

        $this->assertNotNull($storeData, 'Магазин должен быть в выдаче');
        $this->assertSame(1, $storeData['totalInStore'], 'Счётчик считает только товары в наличии');
        $this->assertCount(1, $storeData['products'], 'В списке только товар в наличии');
        $this->assertSame($inStock->id, $storeData['products']->first()->id);
    }

    public function test_ignore_stock_store_counts_zero_stock_products(): void
    {
        $store = Store::factory()->ignoreStock()->create();

        $a = Product::factory()->for($store)->create(['title' => 'MilkTest A', 'quantity' => 5, 'quantity_step' => 1]);
        $b = Product::factory()->for($store)->create(['title' => 'MilkTest B', 'quantity' => 0, 'quantity_step' => 1]);

        $this->searchService->indexProduct($a);
        $this->searchService->indexProduct($b);

        $result = $this->searchService->search('MilkTest', 1, 10, 10);
        $storeData = $result['stores']->firstWhere('store.id', $store->id);

        $this->assertNotNull($storeData);
        $this->assertSame(2, $storeData['totalInStore'], 'В ignore_stock-магазине оба товара доступны');
        $this->assertCount(2, $storeData['products']);
    }

    public function test_search_in_store_total_matches_returned_products(): void
    {
        $store = Store::factory()->create();

        $inStock = Product::factory()->for($store)->create(['title' => 'TeaTest A', 'quantity' => 3, 'quantity_step' => 1]);
        $stale = Product::factory()->for($store)->create(['title' => 'TeaTest B', 'quantity' => 3, 'quantity_step' => 1]);

        $this->searchService->indexProduct($inStock);
        $this->searchService->indexProduct($stale);
        $stale->update(['quantity' => 0]);

        $result = $this->searchService->searchInStore('TeaTest', $store->id, 1, 20);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['products']);
        $this->assertSame($inStock->id, $result['products']->first()->id);
    }
}
