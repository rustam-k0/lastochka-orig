<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тесты GraphQL query `categories_1c_tree` — непагинированная выдача всего
 * дерева 1С-категорий магазина одним запросом.
 *
 * Мотивация: раздел «Категории 1С» грузился ~3 минуты — фронт (OneCColumn)
 * тянул 6206 категорий постранично (31+ HTTP round-trip), а accessor'ы
 * is_linked/is_new делали по 2 exists()-подзапроса НА КАЖДУЮ строку.
 * Tree-запрос отдаёт всё одним махом с eager-load showcaseCategories.
 */
class Categories1cTreeQueryTest extends TestCase
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

    private const QUERY = '
        query ($shopId: Int!, $includeDeleted: Boolean) {
            categories_1c_tree(shop_id: $shopId, include_deleted: $includeDeleted)
        }
    ';

    /**
     * Ответ — JSON-строка (по-полевой резолвинг 6206 строк стоил секунды,
     * см. TreeList): парсим её в массив строк.
     */
    private function treeRows($response): array
    {
        $raw = $response->json('data.categories_1c_tree');
        $this->assertIsString($raw);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @test */
    public function returns_all_categories_in_one_request_beyond_paginate_max(): void
    {
        // 250 > maxCount 200 пагинированного categories_1c — tree отдаёт всё сразу.
        Category1c::factory()->count(250)->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL(self::QUERY, [
            'shopId' => $this->shop->id,
        ]);

        $this->assertCount(250, $this->treeRows($response));
    }

    /** @test */
    public function respects_shop_filter(): void
    {
        // Shop без HasFactory — создаём как в Category1cEventTransformerTest::makeShop.
        $otherShop = Shop::query()->create([
            'title' => 'tree-other-shop-' . uniqid(),
            'code' => 'tree-other-' . uniqid(),
            'address' => 'fixture',
            'phone' => '+70000000001',
        ]);
        Category1c::factory()->count(3)->create(['shop_id' => $this->shop->id]);
        Category1c::factory()->count(2)->create(['shop_id' => $otherShop->id]);

        $response = $this->actingAs($this->admin)->graphQL(self::QUERY, [
            'shopId' => $otherShop->id,
        ]);

        $this->assertCount(2, $this->treeRows($response));
    }

    /** @test */
    public function exposes_linked_flags_and_showcase_categories(): void
    {
        $showcase = Category::query()->create(['title' => 'Витринная ' . uniqid()]);
        $linked = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $linked->showcaseCategories()->attach($showcase->id, ['position' => 0]);
        $unlinked = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        $response = $this->actingAs($this->admin)->graphQL(self::QUERY, [
            'shopId' => $this->shop->id,
        ]);

        $rows = collect($this->treeRows($response))->keyBy('id');
        $this->assertTrue($rows[$linked->id]['is_linked']);
        $this->assertSame($showcase->title, $rows[$linked->id]['showcase_categories'][0]['title']);
        $this->assertFalse($rows[$unlinked->id]['is_linked']);
        $this->assertTrue($rows[$unlinked->id]['is_new'], 'Свежесозданная непривязанная = новая');
        $this->assertFalse($rows[$linked->id]['is_new'], 'Привязанная не считается новой');
    }

    /** @test */
    public function excludes_soft_deleted_by_default_and_includes_with_flag(): void
    {
        $alive = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $deleted = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $deleted->delete();

        $default = $this->actingAs($this->admin)->graphQL(self::QUERY, [
            'shopId' => $this->shop->id,
        ]);
        $ids = collect($this->treeRows($default))->pluck('id');
        $this->assertTrue($ids->contains($alive->id));
        $this->assertFalse($ids->contains($deleted->id));

        $withDeleted = $this->actingAs($this->admin)->graphQL(self::QUERY, [
            'shopId' => $this->shop->id,
            'includeDeleted' => true,
        ]);
        $rows = collect($this->treeRows($withDeleted))->keyBy('id');
        $this->assertTrue($rows->has($deleted->id));
        $this->assertTrue($rows[$deleted->id]['is_source_deleted']);
    }

    /** @test */
    public function guest_is_rejected(): void
    {
        Category1c::factory()->create(['shop_id' => $this->shop->id]);

        $response = $this->graphQL(self::QUERY, ['shopId' => $this->shop->id]);

        $this->assertNull($response->json('data.categories_1c_tree'));
        $this->assertNotEmpty($response->json('errors'));
    }

    /** @test */
    public function is_linked_and_is_new_use_preloaded_relation_without_extra_queries(): void
    {
        $showcase = Category::query()->create(['title' => 'Витринная ' . uniqid()]);
        $linked = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $linked->showcaseCategories()->attach($showcase->id, ['position' => 0]);
        Category1c::factory()->count(9)->create(['shop_id' => $this->shop->id]);

        $models = Category1c::query()
            ->where('shop_id', $this->shop->id)
            ->with('showcaseCategories')
            ->get();

        DB::enableQueryLog();
        foreach ($models as $model) {
            $model->is_linked;
            $model->is_new;
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(
            0,
            $queries,
            'is_linked/is_new при eager-loaded showcaseCategories не должны делать SQL-запросов, получили: '
                . count($queries)
        );
    }
}
