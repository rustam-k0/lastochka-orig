<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderSurchargeRequiredEventTest extends TestCase
{
    use DatabaseTransactions;

    public function test_process_surcharge_required_event_sets_payment_status(): void
    {
        $shopId = \DB::table('shops')->insertGetId([
            'title' => 'Surcharge shop', 'id_1c' => random_int(900000, 999999), 'default' => 1,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $clientId = \DB::table('clients')->insertGetId([
            'password' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $shopId, 'client_id' => $clientId, 'uuid' => 'srch-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => 2, 'amount' => 6000,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = Order::findOrFail($orderId);
        app(OrderEventTransformer::class)->processSurchargeRequiredEvent($order, [
            'event_id' => 'test-event',
            'payment_status' => 'awaiting_surcharge',
            'surcharge_amount' => 1000.0,
        ]);

        $this->assertSame('awaiting_surcharge', Order::find($orderId)->payment_status);
        $this->assertSame(0, (int) Order::find($orderId)->paid);
    }
}
