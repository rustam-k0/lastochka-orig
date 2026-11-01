<?php

namespace Tests\Feature;

use App\Models\Shop;
use Cron\SlotsApi\Services\AssemblyTimeCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Время сборки заказа по категориям магазина (LASTOCHKI-JAMI-11145).
 *
 * AssemblyTimeCalculator должен читать time_to_take из живого pivot
 * shops_categories (его пишет admin_back SetShopCategoriesTime),
 * а не из мёртвой таблицы shop_categories.
 */
class AssemblyTimeCalculatorTest extends TestCase
{
    use DatabaseTransactions;

    private function seedShop(): int
    {
        return (int) DB::table('shops')->insertGetId([
            'title' => 'ATC Shop ' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedProductInCategory(int $shopId, string $articul, int $timeToTake): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'title' => 'ATC Cat ' . uniqid(),
            'link' => 'atc-cat-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->insertGetId([
            'title' => 'ATC Product ' . $articul,
            'articul' => $articul,
            'unit_type_id' => 1,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_product')->insert([
            'product_id' => $productId,
            'category_id' => $categoryId,
        ]);

        DB::table('shops_categories')->insert([
            'shop_id' => $shopId,
            'category_id' => $categoryId,
            'time_to_take' => $timeToTake,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function it_sums_time_to_take_from_shops_categories_pivot()
    {
        $shopId = $this->seedShop();
        $this->seedProductInCategory($shopId, 'ATC-A-' . uniqid(), 45);

        $articul = DB::table('products')->orderByDesc('id')->value('articul');

        $seconds = (new AssemblyTimeCalculator())->calculate(Shop::find($shopId), [
            ['articul' => $articul, 'count' => 2],
        ]);

        $this->assertSame(90, $seconds);
    }

    /** @test */
    public function it_returns_zero_for_empty_or_unknown_products()
    {
        $shopId = $this->seedShop();
        $calculator = new AssemblyTimeCalculator();

        $this->assertSame(0, $calculator->calculate(Shop::find($shopId), []));
        $this->assertSame(0, $calculator->calculate(Shop::find($shopId), [
            ['articul' => 'NO-SUCH-ARTICUL-' . uniqid(), 'count' => 3],
        ]));
    }

    /** @test */
    public function it_uses_time_of_requested_shop_only()
    {
        $shopA = $this->seedShop();
        $articul = 'ATC-SHOP-' . uniqid();
        $this->seedProductInCategory($shopA, $articul, 30);

        // Другой магазин со своим временем у той же категории
        $shopB = $this->seedShop();
        $categoryId = DB::table('shops_categories')->where('shop_id', $shopA)->value('category_id');
        DB::table('shops_categories')->insert([
            'shop_id' => $shopB,
            'category_id' => $categoryId,
            'time_to_take' => 120,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $calculator = new AssemblyTimeCalculator();

        $this->assertSame(30, $calculator->calculate(Shop::find($shopA), [['articul' => $articul, 'count' => 1]]));
        $this->assertSame(120, $calculator->calculate(Shop::find($shopB), [['articul' => $articul, 'count' => 1]]));
    }
}
