<?php

namespace Tests\Feature;

use App\Models\DefaultAttribute;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecalculateProductsFillPercentCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function command_backfills_fill_percent_for_shop(): void
    {
        DefaultAttribute::query()->delete();

        $shop = Shop::create([
            'title' => 'Cmd Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $empty = Product::create([
            'title' => 'Cmd Empty ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'publish_after_unlock' => false,
        ]);
        $withDescription = Product::create([
            'title' => 'Cmd Desc ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'publish_after_unlock' => false,
            'description' => 'Описание',
        ]);

        $this->artisan('products:recalculate-fill-percent', ['--shop_id' => $shop->id])
            ->assertExitCode(0);

        $this->assertSame(3, (int) DB::table('products')->where('id', $empty->id)->value('fill_percent'));
        $this->assertSame(5, (int) DB::table('products')->where('id', $withDescription->id)->value('fill_percent'));
    }
}
