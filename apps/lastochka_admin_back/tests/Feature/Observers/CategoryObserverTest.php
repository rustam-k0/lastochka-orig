<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тест CategoryObserver.deleted: при удалении витринной категории pivot-записи
 * category_1c_category для неё удаляются. Для 1С-категорий, у которых
 * после отвязки не осталось ни одной витринной, linked_at сбрасывается в NULL.
 *
 * Spec §3.5, LASTOCHKI-JAMI-10114.
 */
class CategoryObserverTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): Shop
    {
        return Shop::query()->create([
            'title'   => 'fixture-shop-' . uniqid(),
            'code'    => 'fix-' . uniqid(),
            'address' => 'fixture',
            'phone'   => '+70000000000',
        ]);
    }

    /** @test */
    public function deleting_category_clears_linked_at_on_categories_1c(): void
    {
        $shop     = $this->makeShop();
        $category = Category::factory()->create();

        $linkedCat1c = Category1c::factory()->create([
            'shop_id'   => $shop->id,
            'linked_at' => now(),
        ]);
        $linkedCat1c->showcaseCategories()->attach($category->id, ['position' => 0]);

        $unlinkedCat1c = Category1c::factory()->create([
            'shop_id'   => $shop->id,
            'linked_at' => null,
        ]);

        $category->delete();

        $linkedCat1c->refresh();
        $this->assertNull(
            $linkedCat1c->linked_at,
            'linked_at должен быть сброшен после удаления showcase Category (единственная витрина)'
        );
        $this->assertCount(0, $linkedCat1c->fresh()->showcaseCategories);

        $unlinkedCat1c->refresh();
        $this->assertNull($unlinkedCat1c->linked_at, 'не привязанный linked_at и так был null');
    }

    /** @test */
    public function deleting_category_does_not_clear_linked_at_when_other_showcases_remain(): void
    {
        $shop      = $this->makeShop();
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        // 1С-категория привязана к ДВУМ витринам. После удаления A — одна витрина остаётся.
        $cat1c = Category1c::factory()->create([
            'shop_id'   => $shop->id,
            'linked_at' => now(),
        ]);
        $cat1c->showcaseCategories()->attach([
            $categoryA->id => ['position' => 0],
            $categoryB->id => ['position' => 1],
        ]);

        $categoryA->delete();

        $cat1c->refresh();
        $this->assertNotNull(
            $cat1c->linked_at,
            'linked_at должен оставаться: у 1С-категории ещё есть привязка к categoryB'
        );
        $this->assertSame([$categoryB->id], $cat1c->fresh()->showcaseCategories->pluck('id')->all());
    }

    /** @test */
    public function deleting_category_does_not_affect_categories_1c_linked_to_other_categories(): void
    {
        $shop      = $this->makeShop();
        $categoryA = Category::factory()->create();
        $categoryB = Category::factory()->create();

        $cat1cA = Category1c::factory()->create([
            'shop_id'   => $shop->id,
            'linked_at' => now(),
        ]);
        $cat1cA->showcaseCategories()->attach($categoryA->id, ['position' => 0]);

        $cat1cB = Category1c::factory()->create([
            'shop_id'   => $shop->id,
            'linked_at' => now(),
        ]);
        $cat1cB->showcaseCategories()->attach($categoryB->id, ['position' => 0]);

        $categoryA->delete();

        $cat1cA->refresh();
        $cat1cB->refresh();

        $this->assertNull($cat1cA->linked_at, 'linked_at у привязанной к удалённой категории должен быть null');
        $this->assertNotNull($cat1cB->linked_at, 'linked_at у не затронутой 1С-категории должен остаться');
    }
}
