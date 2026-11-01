<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Category1c;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты artisan-команды `category1c:rebuild-showcase` — зеркало 1С в витрины.
 */
class RebuildShowcaseFromCategory1cTest extends TestCase
{
    use DatabaseTransactions;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::firstOrFail();
    }

    /** @test */
    public function it_mirrors_1c_tree_into_showcase_with_structure_and_links(): void
    {
        // Дерево 1С: root → child(leaf) + root2(leaf).
        $root  = Category1c::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Root1c ' . uniqid()]);
        $child = Category1c::factory()->create(['shop_id' => $this->shop->id, 'parent_id' => $root->id, 'name' => 'Child1c ' . uniqid()]);
        $root2 = Category1c::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Root2c ' . uniqid()]);

        $this->artisan('category1c:rebuild-showcase', ['--shop' => $this->shop->id, '--no-events' => true])
            ->assertExitCode(0);

        // Витрины-зеркала созданы с теми же названиями.
        $vRoot  = Category::where('title', $root->name)->firstOrFail();
        $vChild = Category::where('title', $child->name)->firstOrFail();
        $vRoot2 = Category::where('title', $root2->name)->firstOrFail();

        // Структура parent_id зеркалит 1С: child под root, root/root2 — корни.
        $this->assertNull($vRoot->parent_id);
        $this->assertNull($vRoot2->parent_id);
        $this->assertSame($vRoot->id, $vChild->parent_id);

        // Привязка 1↔1 в pivot category_1c_category.
        $this->assertDatabaseHas('category_1c_category', ['categories_1c_id' => $root->id,  'category_id' => $vRoot->id]);
        $this->assertDatabaseHas('category_1c_category', ['categories_1c_id' => $child->id, 'category_id' => $vChild->id]);
        $this->assertDatabaseHas('category_1c_category', ['categories_1c_id' => $root2->id, 'category_id' => $vRoot2->id]);

        // Все витрины привязаны к магазину.
        foreach ([$vRoot, $vChild, $vRoot2] as $v) {
            $this->assertDatabaseHas('shops_categories', ['shop_id' => $this->shop->id, 'category_id' => $v->id]);
        }

        // linked_at проставлен у 1С-категорий.
        $this->assertNotNull(Category1c::find($root->id)->linked_at);
        $this->assertNotNull(Category1c::find($child->id)->linked_at);
    }

    /** @test */
    public function wipe_clears_old_showcase_but_keeps_categories_1c(): void
    {
        // Старая витрина (мусор) С РЕБЁНКОМ — проверяет, что wipe снимает self-FK parent_id.
        $oldRoot  = Category::create(['title' => 'OldRoot ' . uniqid(), 'link' => 'old-r-' . uniqid()]);
        $oldChild = Category::create(['title' => 'OldChild ' . uniqid(), 'link' => 'old-c-' . uniqid(), 'parent_id' => $oldRoot->id]);
        $oldShowcase = $oldRoot;
        \App\Models\ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $oldRoot->id]);
        \App\Models\ShopCategory::firstOrCreate(['shop_id' => $this->shop->id, 'category_id' => $oldChild->id]);

        $c1c = Category1c::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Fresh1c ' . uniqid()]);

        $this->artisan('category1c:rebuild-showcase', ['--shop' => $this->shop->id, '--wipe' => true, '--no-events' => true])
            ->assertExitCode(0);

        // Старая витрина удалена.
        $this->assertDatabaseMissing('categories', ['id' => $oldShowcase->id]);
        // categories_1c НЕ тронуты.
        $this->assertDatabaseHas('categories_1c', ['id' => $c1c->id]);
        // Новая витрина-зеркало создана и привязана.
        $vFresh = Category::where('title', $c1c->name)->firstOrFail();
        $this->assertDatabaseHas('category_1c_category', ['categories_1c_id' => $c1c->id, 'category_id' => $vFresh->id]);
    }
}
