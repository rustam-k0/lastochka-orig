<?php

declare(strict_types=1);

namespace Tests\Feature\Delivery;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesDeliveryPriceRangesModule;
use Tests\TestCase;

/**
 * MID-280: ступени доставки магазина и снимок условий доставки в заказе.
 */
final class DeliveryPriceRangesStorageTest extends TestCase
{
    use RefreshDatabase;
    use TogglesDeliveryPriceRangesModule;

    protected function setUp(): void
    {
        parent::setUp();
        // Тест написан до тумблера (Task 10): модуль включён, поведение прежнее.
        $this->setDeliveryPriceRangesModule(true);
    }

    public function test_store_keeps_delivery_price_ranges_as_array(): void
    {
        $store = Store::factory()->create([
            'delivery_price' => 200,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 1000, 'price' => 100],
                ['from_amount' => 3000, 'price' => 0],
            ],
        ]);

        $this->assertSame([
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ], $store->fresh()->delivery_price_ranges);
    }

    public function test_store_delivery_ladder_uses_ranges(): void
    {
        $store = Store::factory()->create([
            'delivery_price' => 200,
            'free_delivery_from' => 3000,
            'delivery_price_ranges' => [
                ['from_amount' => 3000, 'price' => 0],
                ['from_amount' => 1000, 'price' => 100],
            ],
        ]);

        $ladder = $store->fresh()->deliveryLadder();

        $this->assertSame(200, $ladder->priceFor(999));
        $this->assertSame(100, $ladder->priceFor(1000));
        $this->assertSame(0, $ladder->priceFor(3000));
    }

    public function test_store_without_ranges_keeps_free_delivery_threshold(): void
    {
        $store = Store::factory()->create([
            'delivery_price' => 200,
            'free_delivery_from' => 1000,
            'delivery_price_ranges' => null,
        ]);

        $ladder = $store->fresh()->deliveryLadder();

        $this->assertSame(200, $ladder->priceFor(999.99));
        $this->assertSame(0, $ladder->priceFor(1000));
    }

    public function test_store_without_delivery_price_has_zero_base_price(): void
    {
        $store = Store::factory()->create([
            'delivery_price' => null,
            'free_delivery_from' => null,
            'delivery_price_ranges' => null,
        ]);

        $this->assertSame(0, $store->fresh()->deliveryLadder()->priceFor(500));
    }

    public function test_order_keeps_delivery_price_snapshot_as_array(): void
    {
        $snapshot = [
            'base_price' => 200,
            'ranges' => [['from_amount' => 1000, 'price' => 100]],
            'checkout_price' => 100,
        ];

        $order = Order::factory()
            ->for(User::factory())
            ->for(Store::factory())
            ->create(['delivery_price_snapshot' => $snapshot]);

        $this->assertEquals($snapshot, $order->fresh()->delivery_price_snapshot);
    }
}
