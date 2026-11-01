<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Product;
use App\Models\Store;
use App\Services\Search\Contracts\SearchClientInterface;
use App\Services\Search\FakeSearchClient;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchServiceBulkIndexTest extends TestCase
{
    use RefreshDatabase;

    private FakeSearchClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $client = app(SearchClientInterface::class);
        $this->assertInstanceOf(FakeSearchClient::class, $client);
        $this->client = $client;
    }

    public function test_indexes_active_products_and_removes_inactive_in_one_call(): void
    {
        $store = Store::factory()->create();
        $active = Product::factory()->create(['store_id' => $store->id, 'is_active' => true]);
        $inactive = Product::factory()->create(['store_id' => $store->id, 'is_active' => false]);

        // Кладём неактивный товар в индекс заранее, чтобы assertFalse ниже
        // проверял фактическое удаление веткой deleteDocuments(), а не
        // отсутствие товара в индексе по умолчанию.
        $this->client->indexDocument(['id' => $inactive->id]);
        $this->assertTrue($this->client->hasDocument($inactive->id));

        app(SearchService::class)->indexProducts(new EloquentCollection([$active, $inactive]));

        $this->assertTrue($this->client->hasDocument($active->id));
        $this->assertFalse($this->client->hasDocument($inactive->id));
    }

    public function test_children_are_not_indexed(): void
    {
        $store = Store::factory()->create();
        $parent = Product::factory()->create(['store_id' => $store->id, 'is_active' => true]);
        $child = Product::factory()->create([
            'store_id' => $store->id,
            'is_active' => true,
            'parent_id' => $parent->id,
        ]);

        // Кладём child в индекс заранее, чтобы assertFalse ниже проверял
        // фактическое удаление веткой deleteDocuments(), а не отсутствие
        // товара в индексе по умолчанию.
        $this->client->indexDocument(['id' => $child->id]);
        $this->assertTrue($this->client->hasDocument($child->id));

        app(SearchService::class)->indexProducts(new EloquentCollection([$child]));

        $this->assertFalse($this->client->hasDocument($child->id));
    }

    public function test_empty_collection_is_noop(): void
    {
        app(SearchService::class)->indexProducts(new EloquentCollection);

        $this->assertTrue(true); // не бросило исключение
    }
}
