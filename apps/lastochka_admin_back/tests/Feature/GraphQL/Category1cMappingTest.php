<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class Category1cMappingTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('admin')->firstOrFail();
        $this->shop = Shop::firstOrFail();
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    private function createShowcaseLeaf(string $prefix = 'Showcase'): Category
    {
        $cat = Category::create([
            'title' => $prefix . ' ' . uniqid(),
            'link'  => 'sc-' . uniqid(),
        ]);
        DB::table('shops_categories')->insert([
            'shop_id'     => $this->shop->id,
            'category_id' => $cat->id,
        ]);
        return $cat;
    }

    public function test_link_attaches_multiple_showcases_additively(): void
    {
        $c1c = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        [$sA, $sB, $sC] = [
            $this->createShowcaseLeaf('A'),
            $this->createShowcaseLeaf('B'),
            $this->createShowcaseLeaf('C'),
        ];

        // Сначала вручную привязываем sA через pivot
        $c1c->showcaseCategories()->attach($sA->id, ['position' => 0]);

        // Теперь через мутацию добавляем sB и sC
        $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
            mutation ($ids: [Int!]!, $showcases: [Int!]!) {
                link_categories_1c_to_showcase(category_1c_ids: $ids, showcase_category_ids: $showcases) {
                    id
                }
            }
        ', [
            'ids'       => [$c1c->id],
            'showcases' => [$sB->id, $sC->id],
        ])->assertJsonPath('data.link_categories_1c_to_showcase.0.id', $c1c->id);

        $linked = $c1c->fresh()->showcaseCategories->pluck('id')->sort()->values()->all();
        $expected = collect([$sA->id, $sB->id, $sC->id])->sort()->values()->all();
        $this->assertSame($expected, $linked);
    }

    public function test_unlink_category_1c_from_showcase_point_removal(): void
    {
        $c1c = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        [$sA, $sB] = [
            $this->createShowcaseLeaf('P'),
            $this->createShowcaseLeaf('Q'),
        ];
        $c1c->showcaseCategories()->attach([
            $sA->id => ['position' => 0],
            $sB->id => ['position' => 1],
        ]);

        $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int!, $showcaseId: Int!) {
                unlink_category_1c_from_showcase(category_1c_id: $id, showcase_category_id: $showcaseId) {
                    id
                }
            }
        ', [
            'id'         => $c1c->id,
            'showcaseId' => $sA->id,
        ])->assertJsonPath('data.unlink_category_1c_from_showcase.id', $c1c->id);

        $remaining = $c1c->fresh()->showcaseCategories->pluck('id')->all();
        $this->assertSame([$sB->id], $remaining);
    }

    public function test_unlink_categories_1c_bulk_detaches_all_showcases(): void
    {
        $c1c = Category1c::factory()->create(['shop_id' => $this->shop->id]);

        [$sA, $sB] = [
            $this->createShowcaseLeaf('X'),
            $this->createShowcaseLeaf('Y'),
        ];
        $c1c->showcaseCategories()->attach([
            $sA->id => ['position' => 0],
            $sB->id => ['position' => 1],
        ]);

        $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
            mutation ($ids: [Int!]!) {
                unlink_categories_1c(category_1c_ids: $ids) {
                    id
                }
            }
        ', ['ids' => [$c1c->id]])->assertJsonPath('data.unlink_categories_1c.0.id', $c1c->id);

        $this->assertCount(0, $c1c->fresh()->showcaseCategories);
    }
}
