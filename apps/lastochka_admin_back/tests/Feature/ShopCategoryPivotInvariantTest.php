<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillShopCategoryAncestorsCommand;
use App\Models\Category;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Инвариант: pivot shops_categories всегда содержит полную ветку от корня
 * до каждого выбранного узла. Покрывает:
 *  - ShopService::syncCategoriesWithEvents (auto-expand предков)
 *  - ShopService::expandWithAncestors (чистая функция)
 *  - BackfillShopCategoryAncestorsCommand (идемпотентный backfill)
 *  - shop_categories GraphQL resolver (полное дерево после leaf-only сохранения)
 */
class ShopCategoryPivotInvariantTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;
    protected Shop $shop;

    protected Category $root;
    protected Category $branch;
    protected Category $leafA;
    protected Category $leafB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->adminUser();

        $this->shop = Shop::create([
            'title' => 'Pivot Invariant Shop ' . uniqid(),
            'code' => 'pivot-invariant-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        // Дерево:
        //   root
        //     └ branch
        //         ├ leafA
        //         └ leafB
        $this->root = Category::create([
            'title' => 'Root ' . uniqid(),
            'parent_id' => null,
        ]);
        $this->branch = Category::create([
            'title' => 'Branch ' . uniqid(),
            'parent_id' => $this->root->id,
        ]);
        $this->leafA = Category::create([
            'title' => 'Leaf A ' . uniqid(),
            'parent_id' => $this->branch->id,
        ]);
        $this->leafB = Category::create([
            'title' => 'Leaf B ' . uniqid(),
            'parent_id' => $this->branch->id,
        ]);
    }

    /** @test */
    public function expand_with_ancestors_climbs_up_to_root()
    {
        $service = new ShopService();

        $expanded = $service->expandWithAncestors([$this->leafA->id]);

        sort($expanded);
        $expected = [$this->root->id, $this->branch->id, $this->leafA->id];
        sort($expected);

        $this->assertSame($expected, $expanded);
    }

    /** @test */
    public function expand_with_ancestors_handles_multiple_leaves_without_duplicates()
    {
        $service = new ShopService();

        $expanded = $service->expandWithAncestors([$this->leafA->id, $this->leafB->id]);

        $this->assertCount(4, $expanded);
        $this->assertEqualsCanonicalizing(
            [$this->root->id, $this->branch->id, $this->leafA->id, $this->leafB->id],
            $expanded
        );
    }

    /** @test */
    public function expand_with_ancestors_ignores_invalid_ids()
    {
        $service = new ShopService();

        $this->assertSame([], $service->expandWithAncestors([]));
        $this->assertSame([], $service->expandWithAncestors([0, -1]));
    }

    /** @test */
    public function sync_leaf_only_selection_adds_ancestors_to_pivot()
    {
        $service = new ShopService();

        $service->syncCategoriesWithEvents($this->shop, [$this->leafA->id]);

        $pivot = $this->shop->categories()->pluck('categories.id')->map(fn($v) => (int) $v)->sort()->values()->all();

        $this->assertEqualsCanonicalizing(
            [$this->root->id, $this->branch->id, $this->leafA->id],
            $pivot
        );
    }

    /** @test */
    public function sync_is_idempotent_when_ancestors_already_included()
    {
        $service = new ShopService();

        $fullBranch = [$this->root->id, $this->branch->id, $this->leafA->id];
        $service->syncCategoriesWithEvents($this->shop, $fullBranch);

        $pivot = $this->shop->categories()->pluck('categories.id')->map(fn($v) => (int) $v)->all();

        $this->assertCount(3, $pivot);
        $this->assertEqualsCanonicalizing($fullBranch, $pivot);
    }

    /** @test */
    public function sync_with_empty_array_removes_all_categories()
    {
        $service = new ShopService();

        $service->syncCategoriesWithEvents($this->shop, [$this->leafA->id]);
        $this->assertCount(3, $this->shop->categories()->get());

        $service->syncCategoriesWithEvents($this->shop, []);

        $this->assertSame(0, $this->shop->categories()->count());
    }

    /** @test */
    public function backfill_command_fills_missing_ancestors_for_orphan_leaves()
    {
        // Имитируем «кривую» историческую привязку — только листья, без предков
        $this->shop->categories()->sync([$this->leafA->id, $this->leafB->id]);

        $this->assertCount(2, $this->shop->categories()->get());

        $this->artisan(BackfillShopCategoryAncestorsCommand::class, [
            '--shop' => $this->shop->id,
        ])->assertExitCode(0);

        $pivot = $this->shop->categories()->pluck('categories.id')->map(fn($v) => (int) $v)->all();

        $this->assertEqualsCanonicalizing(
            [$this->root->id, $this->branch->id, $this->leafA->id, $this->leafB->id],
            $pivot
        );
    }

    /** @test */
    public function backfill_dry_run_does_not_mutate()
    {
        $this->shop->categories()->sync([$this->leafA->id]);

        $this->artisan(BackfillShopCategoryAncestorsCommand::class, [
            '--shop' => $this->shop->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $pivot = $this->shop->categories()->pluck('categories.id')->map(fn($v) => (int) $v)->all();

        $this->assertSame([$this->leafA->id], $pivot);
    }

    /** @test */
    public function get_shop_categories_returns_full_tree_after_leaf_only_save()
    {
        $service = new ShopService();
        $service->syncCategoriesWithEvents($this->shop, [$this->leafA->id, $this->leafB->id]);

        $query = <<<'GQL'
query ($shop_id: Int) {
  shop_categories(shop_id: $shop_id) {
    id
    title
    child_categories {
      id
      title
      child_categories {
        id
        title
      }
    }
  }
}
GQL;

        $response = $this->actingAs($this->user)->graphQL($query, ['shop_id' => $this->shop->id]);

        $tree = $response->json('data.shop_categories');

        $this->assertIsArray($tree);

        $roots = collect($tree)->firstWhere('id', $this->root->id);
        $this->assertNotNull($roots, 'Root категория отсутствует в дереве');

        $branches = collect($roots['child_categories'])->firstWhere('id', $this->branch->id);
        $this->assertNotNull($branches, 'Branch категория отсутствует под root');

        $leafIds = collect($branches['child_categories'])->pluck('id')->sort()->values()->all();
        $expectedLeaves = collect([$this->leafA->id, $this->leafB->id])->sort()->values()->all();

        $this->assertSame($expectedLeaves, $leafIds, 'Оба листа должны оказаться в дереве под branch');
    }
}
