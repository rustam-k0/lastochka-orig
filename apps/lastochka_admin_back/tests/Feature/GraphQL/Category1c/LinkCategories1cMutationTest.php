<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тесты GraphQL mutation `link_categories_1c_to_showcase`.
 * М:N — showcase_category_ids[] (массив).
 * См. spec §5.4.
 */
class LinkCategories1cMutationTest extends TestCase
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

    private function createShowcaseLeaf(string $titlePrefix = 'Showcase'): Category
    {
        $cat = Category::create([
            'title' => $titlePrefix . ' ' . uniqid(),
            'link'  => 'sc-' . uniqid(),
        ]);
        ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $cat->id]);
        return $cat;
    }

    /** @test */
    public function link_attaches_showcase_and_sets_linked_at(): void
    {
        $showcase = $this->createShowcaseLeaf();
        $cat1c    = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!, $scs: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $scs) {
                    id linked_at
                }
            }
        ', ['ids' => [$cat1c->id], 'scs' => [$showcase->id]]);

        $resp->assertJsonPath('data.link_categories_1c_to_showcase.0.id', $cat1c->id);
        $this->assertNotNull($resp->json('data.link_categories_1c_to_showcase.0.linked_at'));

        $this->assertDatabaseHas('category_1c_category', [
            'categories_1c_id' => $cat1c->id,
            'category_id'      => $showcase->id,
        ]);
        $this->assertNotNull(Category1c::find($cat1c->id)->linked_at);
    }

    /** @test */
    public function link_rejects_when_showcase_has_children(): void
    {
        $parent = Category::create(['title' => 'Parent ' . uniqid(), 'link' => 'p-' . uniqid()]);
        Category::create(['title' => 'Child ' . uniqid(), 'link' => 'c-' . uniqid(), 'parent_id' => $parent->id]);
        ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $parent->id]);

        $cat1c = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!, $scs: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $scs) {
                    id
                }
            }
        ', ['ids' => [$cat1c->id], 'scs' => [$parent->id]]);

        $resp->assertJsonStructure(['errors']);
        $this->assertSame('SHOWCASE_NOT_LEAF', $resp->json('errors.0.extensions.code'));
        $this->assertDatabaseMissing('category_1c_category', ['categories_1c_id' => $cat1c->id]);
    }

    /** @test */
    public function link_rejects_when_category_1c_is_soft_deleted(): void
    {
        $showcase = $this->createShowcaseLeaf();
        $cat1c    = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        $cat1c->delete();

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!, $scs: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $scs) {
                    id
                }
            }
        ', ['ids' => [$cat1c->id], 'scs' => [$showcase->id]]);

        $resp->assertJsonStructure(['errors']);
        $this->assertSame('CATEGORY_1C_DELETED', $resp->json('errors.0.extensions.code'));
    }

    /** @test */
    public function link_rejects_when_category_1c_has_children(): void
    {
        $showcase  = $this->createShowcaseLeaf();
        $parent1c  = Category1c::factory()->create(['shop_id' => $this->shop->id]);
        Category1c::factory()->create(['shop_id' => $this->shop->id, 'parent_id' => $parent1c->id]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!, $scs: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $scs) {
                    id
                }
            }
        ', ['ids' => [$parent1c->id], 'scs' => [$showcase->id]]);

        $resp->assertJsonStructure(['errors']);
        $this->assertSame('CATEGORY_1C_NOT_LEAF', $resp->json('errors.0.extensions.code'));
    }

    /** @test */
    public function link_auto_attaches_showcase_to_shop_when_not_linked(): void
    {
        // Витринная категория существует в дереве, но НЕ привязана к магазину
        // Category1c (нет записи в shops_categories) — типовой сценарий
        // только что созданной витрины. Привязка должна сама добавить её в магазин.
        $showcase = Category::create([
            'title' => 'Detached ' . uniqid(),
            'link'  => 'd-' . uniqid(),
        ]);
        $cat1c = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!, $scs: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $scs) {
                    id linked_at
                }
            }
        ', ['ids' => [$cat1c->id], 'scs' => [$showcase->id]]);

        // Ошибки нет — привязка прошла.
        $resp->assertJsonMissingPath('errors');
        $this->assertNotNull($resp->json('data.link_categories_1c_to_showcase.0.linked_at'));

        // Витрина автоматически добавлена в магазин 1С-категории.
        $this->assertDatabaseHas('shops_categories', [
            'shop_id'     => $this->shop->id,
            'category_id' => $showcase->id,
        ]);

        // Привязка 1С↔витрина создана.
        $this->assertDatabaseHas('category_1c_category', [
            'categories_1c_id' => $cat1c->id,
            'category_id'      => $showcase->id,
        ]);
    }

    /** @test */
    public function link_auto_attaches_showcase_with_all_ancestors_to_shop(): void
    {
        // Витрина-лист с родителем, который тоже не привязан к магазину.
        // Привязка должна добавить в магазин и витрину, и её предка
        // (инвариант shops_categories: полная ветка от корня).
        $parent = Category::create(['title' => 'AncRoot ' . uniqid(), 'link' => 'ar-' . uniqid()]);
        $leaf   = Category::create([
            'title'     => 'AncLeaf ' . uniqid(),
            'link'      => 'al-' . uniqid(),
            'parent_id' => $parent->id,
        ]);
        $cat1c  = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!, $scs: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $scs) {
                    id
                }
            }
        ', ['ids' => [$cat1c->id], 'scs' => [$leaf->id]]);

        $resp->assertJsonMissingPath('errors');

        $this->assertDatabaseHas('shops_categories', [
            'shop_id'     => $this->shop->id,
            'category_id' => $leaf->id,
        ]);
        $this->assertDatabaseHas('shops_categories', [
            'shop_id'     => $this->shop->id,
            'category_id' => $parent->id,
        ]);
    }
}
