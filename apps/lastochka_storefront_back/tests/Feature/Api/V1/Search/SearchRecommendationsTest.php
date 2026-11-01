<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Search;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Product::unsetEventDispatcher();
    }

    public function test_returns_products_flagged_on_search_page(): void
    {
        $store = Store::factory()->create();

        $flagged = Product::factory()->count(3)->create([
            'store_id' => $store->id,
            'on_search_page' => true,
        ]);
        Product::factory()->count(2)->create([
            'store_id' => $store->id,
            'on_search_page' => false,
        ]);

        $response = $this->getJson('/api/v1/search/recommendations?limit=3');

        $response->assertOk();
        $this->assertSame(3, $response->json('total'));

        $returnedIds = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame($flagged->pluck('id')->sort()->values()->all(), $returnedIds);
    }

    public function test_falls_back_to_fresh_products_when_flagged_count_is_below_limit(): void
    {
        $store = Store::factory()->create();

        $flagged = Product::factory()->create([
            'store_id' => $store->id,
            'on_search_page' => true,
        ]);
        $fresh = Product::factory()->count(4)->create([
            'store_id' => $store->id,
            'on_search_page' => false,
        ]);

        $response = $this->getJson('/api/v1/search/recommendations?limit=5');

        $response->assertOk();
        $this->assertSame(5, $response->json('total'));

        $returnedIds = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($flagged->id, $returnedIds);
        foreach ($fresh as $p) {
            $this->assertContains($p->id, $returnedIds);
        }
    }

    public function test_respects_limit_and_defaults_to_20(): void
    {
        $store = Store::factory()->create();
        Product::factory()->count(25)->create([
            'store_id' => $store->id,
            'on_search_page' => true,
        ]);

        $defaultResponse = $this->getJson('/api/v1/search/recommendations');
        $defaultResponse->assertOk();
        $this->assertSame(20, $defaultResponse->json('total'));
        $this->assertCount(20, $defaultResponse->json('data'));

        $limited = $this->getJson('/api/v1/search/recommendations?limit=5');
        $limited->assertOk();
        $this->assertCount(5, $limited->json('data'));
    }

    public function test_excludes_inactive_children_zero_price_and_out_of_stock_products(): void
    {
        $store = Store::factory()->create();

        $valid = Product::factory()->create([
            'store_id' => $store->id,
            'on_search_page' => true,
            'is_active' => true,
            'price' => 100,
            'quantity' => 10,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'on_search_page' => true,
            'is_active' => false,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'on_search_page' => true,
            'price' => 0,
        ]);
        Product::factory()->create([
            'store_id' => $store->id,
            'on_search_page' => true,
            'quantity' => 0,
        ]);

        $parent = Product::factory()->create(['store_id' => $store->id, 'on_search_page' => true]);
        Product::factory()->create([
            'store_id' => $store->id,
            'on_search_page' => true,
            'parent_id' => $parent->id,
        ]);

        $response = $this->getJson('/api/v1/search/recommendations?limit=10');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($valid->id, $ids);
        $this->assertContains($parent->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_excludes_products_from_inactive_stores(): void
    {
        $active = Store::factory()->create(['is_active' => true]);
        $inactive = Store::factory()->create(['is_active' => false]);

        $visible = Product::factory()->create([
            'store_id' => $active->id,
            'on_search_page' => true,
        ]);
        Product::factory()->create([
            'store_id' => $inactive->id,
            'on_search_page' => true,
        ]);

        $response = $this->getJson('/api/v1/search/recommendations?limit=10');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$visible->id], $ids);
    }

    public function test_returns_empty_when_no_products_exist(): void
    {
        Store::factory()->create();

        $response = $this->getJson('/api/v1/search/recommendations');

        $response->assertOk();
        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('data'));
    }

    public function test_validates_limit_boundaries(): void
    {
        $this->getJson('/api/v1/search/recommendations?limit=0')
            ->assertStatus(422);
        $this->getJson('/api/v1/search/recommendations?limit=51')
            ->assertStatus(422);
        $this->getJson('/api/v1/search/recommendations?limit=abc')
            ->assertStatus(422);
    }
}
