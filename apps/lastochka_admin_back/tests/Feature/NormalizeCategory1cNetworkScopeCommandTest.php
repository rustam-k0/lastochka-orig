<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCategory1cNetworkMode;
use Tests\TestCase;

class NormalizeCategory1cNetworkScopeCommandTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithCategory1cNetworkMode;

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    private function makeProduct(Shop $shop, int $cat1cId): Product
    {
        $product = new Product();
        $product->shop_id = $shop->id;
        $product->title = 'Test product ' . uniqid();
        $product->category_1c_id = $cat1cId;
        $product->save();

        return $product;
    }

    public function test_fails_when_network_mode_off(): void
    {
        $this->disableCategory1cNetworkMode();

        $this->artisan('category-1c:normalize-network-scope')->assertFailed();
    }

    public function test_moves_foreign_rows_without_duplicate_to_anchor(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $foreign = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-move',
        ]);

        $this->artisan('category-1c:normalize-network-scope')->assertSuccessful();

        $this->assertSame($anchor->id, $foreign->fresh()->shop_id);
    }

    public function test_merges_duplicate_into_anchor_row(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $anchorRow = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-norm-merge',
        ]);
        $dupRow = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-merge',
        ]);

        $showcase = Category::create(['title' => 'Showcase ' . uniqid(), 'parent_id' => null]);
        $dupRow->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $product = $this->makeProduct($other, $dupRow->id);

        $dupChild = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-merge-child',
            'parent_id' => $dupRow->id,
        ]);

        $this->artisan('category-1c:normalize-network-scope')->assertSuccessful();

        $this->assertNull(Category1c::withTrashed()->find($dupRow->id));
        $this->assertSame($anchorRow->id, $product->fresh()->category_1c_id);
        $this->assertTrue(
            $anchorRow->fresh()->showcaseCategories->pluck('id')->contains($showcase->id)
        );
        $this->assertSame($anchorRow->id, $dupChild->fresh()->parent_id);
        $this->assertSame($anchor->id, $dupChild->fresh()->shop_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $foreign = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-dry',
        ]);

        $this->artisan('category-1c:normalize-network-scope --dry-run')->assertSuccessful();

        $this->assertSame($other->id, $foreign->fresh()->shop_id);
    }

    public function test_resolves_parent_links_inside_anchor_after_move(): void
    {
        $anchor = $this->makeShop('anchor-' . uniqid());
        $other = $this->makeShop('other-' . uniqid());
        $this->enableCategory1cNetworkMode($anchor->id);

        $parentAnchor = Category1c::factory()->create([
            'shop_id' => $anchor->id,
            'external_id' => 'guid-norm-parent',
        ]);
        $childForeign = Category1c::factory()->create([
            'shop_id' => $other->id,
            'external_id' => 'guid-norm-child',
            'parent_id' => null,
            'parent_external_id' => 'guid-norm-parent',
        ]);

        $this->artisan('category-1c:normalize-network-scope')->assertSuccessful();

        $this->assertSame($parentAnchor->id, $childForeign->fresh()->parent_id);
    }
}
