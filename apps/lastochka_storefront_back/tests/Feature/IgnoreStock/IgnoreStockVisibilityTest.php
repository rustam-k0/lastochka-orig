<?php

declare(strict_types=1);

namespace Tests\Feature\IgnoreStock;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Магазин с ignore_stock=1 («Не учитывать остатки») должен отдавать товары
 * с нулевым остатком в поиске, листингах и на карточке — иначе они не
 * отдаются, пока остаток не положительный.
 */
final class IgnoreStockVisibilityTest extends TestCase
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

    public function test_scope_in_stock_includes_zero_stock_product_of_ignore_stock_store(): void
    {
        $ignoreStockStore = Store::factory()->ignoreStock()->create();
        $normalStore = Store::factory()->create();

        $eggIgnore = Product::factory()->for($ignoreStockStore)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);
        $eggNormal = Product::factory()->for($normalStore)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $inStockIds = Product::query()->inStock()->pluck('id')->all();

        $this->assertContains($eggIgnore->id, $inStockIds, 'zero-stock товар ignore_stock-магазина должен проходить inStock');
        $this->assertNotContains($eggNormal->id, $inStockIds, 'zero-stock товар обычного магазина не должен проходить inStock');
    }

    public function test_scope_purchasable_includes_zero_stock_product_of_ignore_stock_store(): void
    {
        $ignoreStockStore = Store::factory()->ignoreStock()->create();
        $normalStore = Store::factory()->create();

        $eggIgnore = Product::factory()->for($ignoreStockStore)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);
        $eggNormal = Product::factory()->for($normalStore)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $purchasableIds = Product::query()->purchasable()->pluck('id')->all();

        $this->assertContains($eggIgnore->id, $purchasableIds);
        $this->assertNotContains($eggNormal->id, $purchasableIds);
    }

    public function test_search_returns_zero_stock_product_for_ignore_stock_store(): void
    {
        $store = Store::factory()->ignoreStock()->create();

        $product = Product::factory()->for($store)->create([
            'title' => 'Яйца В категории',
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->searchService->indexProduct($product);

        $query = urlencode('Яйца');
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query={$query}");

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id);
    }

    public function test_search_hides_zero_stock_product_for_normal_store(): void
    {
        $store = Store::factory()->create();

        $product = Product::factory()->for($store)->create([
            'title' => 'Яйца В категории',
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->searchService->indexProduct($product);

        $query = urlencode('Яйца');
        $response = $this->getJson("/api/v1/search/stores/{$store->id}/products?query={$query}");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_detail_available_for_zero_stock_ignore_stock_store(): void
    {
        $store = Store::factory()->ignoreStock()->create();

        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);
    }

    public function test_product_detail_404_for_zero_stock_normal_store(): void
    {
        $store = Store::factory()->create();

        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
        ]);

        $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    /**
     * LASTOCHKI-JAMI-11983 (побочная находка): товарный `stock_mode='ignore'`
     * (в отличие от магазинного `stores.ignore_stock`) не учитывался при
     * построении Meilisearch-документа — `in_stock` считался только по
     * остатку, и такой товар не находился полнотекстовым поиском, хотя
     * корректно проходил Product::scopePurchasable()/scopeInStock().
     */
    public function test_product_level_ignore_indexes_as_in_stock_even_with_zero_quantity(): void
    {
        $store = Store::factory()->create(); // обычный магазин, БЕЗ ignore_stock

        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
            'stock_mode' => 'ignore',
        ]);

        $this->searchService->indexProduct($product);

        $doc = $this->searchClient->indexedDocument($product->id);

        $this->assertNotNull($doc);
        $this->assertTrue(
            $doc['in_stock'],
            'Товар с product-level stock_mode=ignore должен индексироваться как in_stock=true даже при quantity=0'
        );
    }

    public function test_normal_stock_mode_with_zero_quantity_indexes_as_out_of_stock(): void
    {
        $store = Store::factory()->create();

        $product = Product::factory()->for($store)->create([
            'quantity' => 0,
            'quantity_step' => 1,
            'stock_mode' => 'normal',
        ]);

        $this->searchService->indexProduct($product);

        $doc = $this->searchClient->indexedDocument($product->id);

        $this->assertNotNull($doc);
        $this->assertFalse($doc['in_stock']);
    }
}
