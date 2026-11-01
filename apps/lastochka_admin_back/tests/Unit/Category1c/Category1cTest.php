<?php

declare(strict_types=1);

namespace Tests\Unit\Category1c;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Unit-тесты модели Category1c.
 *
 * Покрывают аксессоры (is_leaf, is_linked, is_source_deleted, is_new),
 * soft-delete семантику и базовые отношения (parent/children/showcaseCategories M:N).
 *
 * Контекст: см. docs/superpowers/specs/2026-05-05-1c-categories-mapping-design.md §3.4.
 */
class Category1cTest extends TestCase
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

    private function makeCategory1c(array $attrs = []): Category1c
    {
        if (!array_key_exists('shop_id', $attrs)) {
            $attrs['shop_id'] = $this->makeShop()->id;
        }
        return Category1c::factory()->create($attrs);
    }

    /** @test */
    public function test_is_leaf_returns_true_when_no_children(): void
    {
        $node = $this->makeCategory1c();

        $this->assertTrue($node->is_leaf, 'Категория без дочерних должна быть листом.');
    }

    /** @test */
    public function test_is_leaf_returns_false_when_has_children(): void
    {
        $parent = $this->makeCategory1c();
        $this->makeCategory1c(['shop_id' => $parent->shop_id, 'parent_id' => $parent->id]);

        $parent->load('children');

        $this->assertFalse($parent->is_leaf, 'Категория с дочерними не должна быть листом.');
    }

    /** @test */
    public function test_is_linked_reflects_showcase_categories(): void
    {
        $unlinked = $this->makeCategory1c();
        $this->assertFalse($unlinked->is_linked, 'Без привязанных витрин is_linked=false.');

        $showcase = Category::factory()->create();
        $unlinked->showcaseCategories()->attach($showcase->id, ['position' => 0]);
        $unlinked->unsetRelation('showcaseCategories');

        $this->assertTrue($unlinked->is_linked, 'После attach витрины is_linked=true.');
    }

    /** @test */
    public function test_soft_delete_keeps_record_with_deleted_at(): void
    {
        $node = $this->makeCategory1c();
        $id = $node->id;

        $node->delete();

        $this->assertNull(Category1c::query()->find($id), 'После soft-delete обычный find не находит.');

        $trashed = Category1c::query()->withTrashed()->find($id);
        $this->assertNotNull($trashed, 'withTrashed возвращает запись.');
        $this->assertNotNull($trashed->deleted_at, 'deleted_at заполнен.');
        $this->assertTrue($trashed->is_source_deleted, 'is_source_deleted=true для удалённой.');
    }

    /** @test */
    public function test_is_new_true_when_recent_and_unlinked(): void
    {
        $node = $this->makeCategory1c([
            'created_at' => Carbon::now()->subDays(5),
        ]);

        $this->assertTrue($node->is_new, 'created_at < 30 дней назад + не привязана → is_new=true.');
    }

    /** @test */
    public function test_is_new_false_when_old(): void
    {
        $node = $this->makeCategory1c([
            'created_at' => Carbon::now()->subDays(45),
        ]);

        $this->assertFalse($node->is_new, 'created_at > 30 дней назад → is_new=false.');
    }

    /** @test */
    public function test_is_new_false_when_linked(): void
    {
        $showcase = Category::factory()->create();
        $node = $this->makeCategory1c([
            'created_at' => Carbon::now()->subDays(5),
        ]);
        $node->showcaseCategories()->attach($showcase->id, ['position' => 0]);
        $node->unsetRelation('showcaseCategories');

        $this->assertFalse($node->is_new, 'Привязанная категория не считается новой.');
    }

    /** @test */
    public function test_relations_resolve(): void
    {
        $shop     = $this->makeShop();
        $showcase = Category::factory()->create();
        $parent   = $this->makeCategory1c(['shop_id' => $shop->id]);
        $child    = $this->makeCategory1c([
            'shop_id'   => $shop->id,
            'parent_id' => $parent->id,
        ]);
        $child->showcaseCategories()->attach($showcase->id, ['position' => 0]);

        $this->assertSame($shop->id, $child->shop->id);
        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($child->showcaseCategories->pluck('id')->contains($showcase->id));
        $this->assertTrue($parent->children->pluck('id')->contains($child->id));
    }
}
