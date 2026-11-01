<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тесты GraphQL query `categories_1c` и связанных полей.
 * М:N — привязка через pivot category_1c_category.
 * См. spec §5.2.
 */
class Categories1cQueryTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('admin')->firstOrFail();
        $this->shop  = Shop::firstOrFail();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    /** @test */
    public function returns_paginated_categories_for_shop(): void
    {
        Category1c::factory()->count(5)->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, first: 50) {
                    data { id name shop_id }
                    paginatorInfo { total }
                }
            }
        ', ['shopId' => $this->shop->id]);

        $response->assertJsonPath('data.categories_1c.paginatorInfo.total', 5);
        $this->assertCount(5, $response->json('data.categories_1c.data'));
    }

    /**
     * Регрессия: @paginate без стабильного ORDER BY возвращал строки в
     * недетерминированном порядке между страницами → при сборке всех страниц
     * на фронте (OneCColumn) часть категорий дублировалась, часть выпадала,
     * и в дереве пропадали промежуточные узлы. Стабильный orderBy('id') в
     * Category1cQueries\PaginatedList гарантирует полное объединение без дублей.
     *
     * @test
     */
    public function pagination_is_stable_and_complete_across_all_pages(): void
    {
        $created     = Category1c::factory()->count(25)->create(['shop_id' => $this->shop->id]);
        $expectedIds = $created->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $collected = [];
        for ($page = 1; $page <= 3; $page++) {
            $resp = $this->actingAs($this->admin)->graphQL('
                query ($shopId: Int!, $page: Int) {
                    categories_1c(shop_id: $shopId, first: 10, page: $page) {
                        data { id }
                        paginatorInfo { total }
                    }
                }
            ', ['shopId' => $this->shop->id, 'page' => $page]);

            $resp->assertJsonPath('data.categories_1c.paginatorInfo.total', 25);
            foreach ($resp->json('data.categories_1c.data') as $row) {
                $collected[] = (int) $row['id'];
            }
        }

        // Страницы не должны пересекаться (нет дублей).
        $this->assertCount(count($collected), array_unique($collected), 'страницы не должны пересекаться');

        // Объединение всех страниц = все категории без пропусков.
        $union = $collected;
        sort($union);
        $this->assertSame($expectedIds, $union, 'объединение страниц должно дать все категории без пропусков');
    }

    /** @test */
    public function pagination_orders_categories_by_id(): void
    {
        Category1c::factory()->count(6)->create(['shop_id' => $this->shop->id]);

        $resp = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, first: 50) {
                    data { id }
                }
            }
        ', ['shopId' => $this->shop->id]);

        $ids    = array_map(fn ($r) => (int) $r['id'], $resp->json('data.categories_1c.data'));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'категории должны возвращаться в детерминированном порядке по id');
    }

    /** @test */
    public function filters_by_linked_status_linked(): void
    {
        $showcase = Category::create(['title' => 'Test Showcase ' . uniqid(), 'link' => 'tsc-' . uniqid()]);

        $linkedCats = Category1c::factory()->count(3)->create([
            'shop_id'   => $this->shop->id,
            'linked_at' => now(),
        ]);
        foreach ($linkedCats as $i => $cat) {
            $cat->showcaseCategories()->attach($showcase->id, ['position' => $i]);
        }

        Category1c::factory()->count(2)->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, linked_status: LINKED, first: 50) {
                    paginatorInfo { total }
                }
            }
        ', ['shopId' => $this->shop->id]);

        $response->assertJsonPath('data.categories_1c.paginatorInfo.total', 3);
    }

    /** @test */
    public function filters_by_linked_status_unlinked(): void
    {
        $showcase = Category::create(['title' => 'Test Showcase U ' . uniqid(), 'link' => 'tsc-u-' . uniqid()]);

        $linkedCats = Category1c::factory()->count(2)->create([
            'shop_id'   => $this->shop->id,
            'linked_at' => now(),
        ]);
        foreach ($linkedCats as $i => $cat) {
            $cat->showcaseCategories()->attach($showcase->id, ['position' => $i]);
        }

        Category1c::factory()->count(4)->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, linked_status: UNLINKED, first: 50) {
                    paginatorInfo { total }
                }
            }
        ', ['shopId' => $this->shop->id]);

        $response->assertJsonPath('data.categories_1c.paginatorInfo.total', 4);
    }

    /** @test */
    public function search_finds_by_substring(): void
    {
        $marker = 'UniqueXYZ' . mt_rand(1000, 9999);
        Category1c::factory()->create([
            'shop_id' => $this->shop->id,
            'name'    => 'Категория ' . $marker . ' тестовая',
        ]);
        Category1c::factory()->count(3)->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!, $q: String) {
                categories_1c(shop_id: $shopId, search: $q, first: 50) {
                    paginatorInfo { total }
                    data { name }
                }
            }
        ', ['shopId' => $this->shop->id, 'q' => $marker]);

        $response->assertJsonPath('data.categories_1c.paginatorInfo.total', 1);
    }

    /** @test */
    public function only_leaves_filter_excludes_categories_with_children(): void
    {
        $parent = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        Category1c::factory()->create(['shop_id' => $this->shop->id, 'parent_id' => $parent->id]);
        Category1c::factory()->count(2)->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, only_leaves: true, first: 50) {
                    paginatorInfo { total }
                }
            }
        ', ['shopId' => $this->shop->id]);

        // 1 child + 2 standalone = 3 листа (parent имеет children — не лист)
        $response->assertJsonPath('data.categories_1c.paginatorInfo.total', 3);
    }

    /** @test */
    public function include_deleted_returns_soft_deleted(): void
    {
        Category1c::factory()->count(2)->create(['shop_id' => $this->shop->id]);
        $deleted = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $deleted->delete();

        $without = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, first: 50) {
                    paginatorInfo { total }
                }
            }
        ', ['shopId' => $this->shop->id]);
        $without->assertJsonPath('data.categories_1c.paginatorInfo.total', 2);

        $withDeleted = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c(shop_id: $shopId, include_deleted: true, first: 50) {
                    paginatorInfo { total }
                }
            }
        ', ['shopId' => $this->shop->id]);
        $withDeleted->assertJsonPath('data.categories_1c.paginatorInfo.total', 3);
    }

    /** @test */
    public function categories_1c_new_count_returns_unlinked_recent_count(): void
    {
        $showcase = Category::create(['title' => 'NC ' . uniqid(), 'link' => 'nc-' . uniqid()]);

        // 2 unlinked recent
        Category1c::factory()->count(2)->create(['shop_id' => $this->shop->id]);

        // 1 linked — через pivot
        $linked = Category1c::factory()->create([
            'shop_id'   => $this->shop->id,
            'linked_at' => now(),
        ]);
        $linked->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        // 1 old (unlinked but created > 30 days ago)
        $old = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $old->created_at = now()->subDays(45);
        $old->save();

        $response = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                categories_1c_new_count(shop_id: $shopId)
            }
        ', ['shopId' => $this->shop->id]);

        $response->assertJsonPath('data.categories_1c_new_count', 2);
    }

    /** @test */
    public function category_1c_sync_batch_returns_latest_or_null(): void
    {
        $resp = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                category_1c_sync_batch(shop_id: $shopId) { id status }
            }
        ', ['shopId' => $this->shop->id]);
        $this->assertNull($resp->json('data.category_1c_sync_batch'));

        \App\Models\Category1cSyncBatch::create([
            'shop_id'    => $this->shop->id,
            'status'     => 'IN_PROGRESS',
            'started_at' => now(),
        ]);

        $resp2 = $this->actingAs($this->admin)->graphQL('
            query ($shopId: Int!) {
                category_1c_sync_batch(shop_id: $shopId) { id status }
            }
        ', ['shopId' => $this->shop->id]);
        $resp2->assertJsonPath('data.category_1c_sync_batch.status', 'IN_PROGRESS');
    }
}
