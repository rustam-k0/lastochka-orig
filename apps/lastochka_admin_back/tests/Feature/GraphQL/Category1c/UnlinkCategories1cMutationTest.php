<?php

declare(strict_types=1);

namespace Tests\Feature\GraphQL\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use App\Models\ShopCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Тесты GraphQL mutation `unlink_categories_1c`.
 * М:N — полная очистка pivot для переданных 1С-категорий.
 * См. spec §5.4.
 */
class UnlinkCategories1cMutationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $admin;
    protected Shop $shop;
    protected Category $showcase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('admin')->firstOrFail();
        $this->shop  = Shop::firstOrFail();
        $this->showcase = Category::create([
            'title' => 'Showcase ' . uniqid(),
            'link'  => 'sc-' . uniqid(),
        ]);
        ShopCategory::firstOrCreate([
            'shop_id'     => $this->shop->id,
            'category_id' => $this->showcase->id,
        ]);
        app(\Nuwave\Lighthouse\Schema\AST\ASTCache::class)->clear();
    }

    /** @test */
    public function unlink_detaches_all_showcases_and_clears_linked_at(): void
    {
        $cat1c = Category1c::factory()->create([
            'shop_id'   => $this->shop->id,
            'linked_at' => now(),
        ]);
        $cat1c->showcaseCategories()->attach($this->showcase->id, ['position' => 0]);

        $resp = $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!) {
                unlink_categories_1c(category_1c_ids: $ids) {
                    id linked_at last_synced_at
                }
            }
        ', ['ids' => [$cat1c->id]]);

        $this->assertNull($resp->json('data.unlink_categories_1c.0.linked_at'));

        $this->assertCount(0, $cat1c->fresh()->showcaseCategories);
        $this->assertNull(Category1c::find($cat1c->id)->linked_at);
    }

    /** @test */
    public function unlink_preserves_last_synced_at(): void
    {
        $syncedAt = now()->subHours(2)->setMicroseconds(0);
        $cat1c = Category1c::factory()->create([
            'shop_id'        => $this->shop->id,
            'linked_at'      => now(),
            'last_synced_at' => $syncedAt,
        ]);
        $cat1c->showcaseCategories()->attach($this->showcase->id, ['position' => 0]);

        $this->actingAs($this->admin)->graphQL('
            mutation ($ids: [Int!]!) {
                unlink_categories_1c(category_1c_ids: $ids) { id }
            }
        ', ['ids' => [$cat1c->id]]);

        $reloaded = Category1c::find($cat1c->id);
        $this->assertNotNull($reloaded->last_synced_at);
        $this->assertEquals(
            $syncedAt->format('Y-m-d H:i:s'),
            $reloaded->last_synced_at->format('Y-m-d H:i:s'),
        );
    }
}
