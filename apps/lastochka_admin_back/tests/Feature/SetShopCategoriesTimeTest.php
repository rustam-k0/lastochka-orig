<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Shop\SetShopCategoriesTime;
use App\Models\Category;
use App\Models\Shop;
use App\Models\ShopCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Время сборки и приоритет категорий магазина (LASTOCHKI-JAMI-11145).
 *
 * Мутация SetShopCategoriesTime должна сохранять time_to_take/position
 * в живой pivot shops_categories (его читают slots-api и delivery_back),
 * не разрушая привязки категорий к магазину.
 */
class SetShopCategoriesTimeTest extends TestCase
{
    use DatabaseTransactions;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'title' => 'Assembly Time Shop',
            'code' => 'assembly-time-shop-' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
    }

    protected function callMutation(array $items): array
    {
        return (new SetShopCategoriesTime())(null, [
            'shop_id' => $this->shop->id,
            'items' => $items,
        ]);
    }

    /** @test */
    public function it_saves_time_and_position_to_shops_categories_pivot()
    {
        $category = Category::create(['title' => 'Leaf ' . uniqid()]);
        ShopCategory::create(['shop_id' => $this->shop->id, 'category_id' => $category->id]);

        $response = $this->callMutation([
            ['category_id' => $category->id, 'time_to_take' => 45, 'position' => 2],
        ]);

        $this->assertSame(200, $response['code']);

        $row = ShopCategory::where('shop_id', $this->shop->id)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(45, (int) $row->time_to_take);
        $this->assertSame(2, (int) $row->position);
    }

    /** @test */
    public function it_creates_pivot_row_for_category_not_yet_linked_to_shop()
    {
        $category = Category::create(['title' => 'Unlinked ' . uniqid()]);

        $this->callMutation([
            ['category_id' => $category->id, 'time_to_take' => 30, 'position' => 0],
        ]);

        $row = ShopCategory::where('shop_id', $this->shop->id)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(30, (int) $row->time_to_take);
    }

    /** @test */
    public function it_creates_ancestor_rows_for_hierarchy_integrity()
    {
        $parent = Category::create(['title' => 'Parent ' . uniqid()]);
        $child = Category::create(['title' => 'Child ' . uniqid(), 'parent_id' => $parent->id]);

        $this->callMutation([
            ['category_id' => $child->id, 'time_to_take' => 60, 'position' => 1],
        ]);

        $this->assertNotNull(
            ShopCategory::where('shop_id', $this->shop->id)->where('category_id', $parent->id)->first(),
            'Родительская категория должна быть привязана к магазину автоматически'
        );
    }

    /** @test */
    public function it_does_not_remove_other_linked_categories()
    {
        $configured = Category::create(['title' => 'Configured ' . uniqid()]);
        $other = Category::create(['title' => 'Other ' . uniqid()]);
        ShopCategory::create(['shop_id' => $this->shop->id, 'category_id' => $configured->id]);
        ShopCategory::create(['shop_id' => $this->shop->id, 'category_id' => $other->id]);

        $this->callMutation([
            ['category_id' => $configured->id, 'time_to_take' => 20, 'position' => 0],
        ]);

        $this->assertNotNull(
            ShopCategory::where('shop_id', $this->shop->id)->where('category_id', $other->id)->first(),
            'Мутация не должна удалять привязки категорий, отсутствующих в items'
        );
    }

    /** @test */
    public function it_updates_existing_values_without_duplicating_rows()
    {
        $category = Category::create(['title' => 'Repeat ' . uniqid()]);

        $this->callMutation([['category_id' => $category->id, 'time_to_take' => 10, 'position' => 0]]);
        $this->callMutation([['category_id' => $category->id, 'time_to_take' => 90, 'position' => 5]]);

        $rows = ShopCategory::where('shop_id', $this->shop->id)
            ->where('category_id', $category->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(90, (int) $rows->first()->time_to_take);
        $this->assertSame(5, (int) $rows->first()->position);
    }
}
