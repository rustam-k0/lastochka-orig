<?php

namespace Tests\Feature;

use App\Events\ProductUpdated;
use App\Listeners\RecalculateProductFillPercent;
use App\Models\DefaultAttribute;
use App\Models\Product;
use App\Models\Shop;
use App\Services\ProductFillPercentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecalculateProductFillPercentListenerTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function listener_is_registered_for_product_updated(): void
    {
        Event::fake();
        Event::assertListening(ProductUpdated::class, RecalculateProductFillPercent::class);
    }

    /** @test */
    public function handle_recalculates_fill_percent(): void
    {
        DefaultAttribute::query()->delete();

        $shop = Shop::create([
            'title' => 'Listener Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);
        $product = Product::create([
            'title' => 'Listener Product ' . uniqid(),
            'shop_id' => $shop->id,
            'unit_type_id' => 1,
            'status_id' => 1,
            'publish_after_unlock' => false,
            'description' => 'Описание',
        ]);
        $this->assertSame(0, (int) DB::table('products')->where('id', $product->id)->value('fill_percent'));

        (new RecalculateProductFillPercent(app(ProductFillPercentService::class)))
            ->handle(new ProductUpdated($product));

        // max=40, набрано 1 (база) + 1 (description) → round(5) = 5
        $this->assertSame(5, (int) DB::table('products')->where('id', $product->id)->value('fill_percent'));
    }
}
