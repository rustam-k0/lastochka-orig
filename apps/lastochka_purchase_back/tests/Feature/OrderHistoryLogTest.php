<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryLogTest extends TestCase
{
    use DatabaseTransactions;

    private function makeShop(): int
    {
        return \DB::table('shops')->insertGetId([
            'title' => 'OrderHistory model shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeClient(): int
    {
        return \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_log_creates_order_history_row(): void
    {
        $order = Order::factory()->create([
            'shop_id' => $this->makeShop(),
            'client_id' => $this->makeClient(),
            'payment_type_id' => 1,
            'status_id' => Status::ORDER_CREATED,
        ]);

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_CANCELED,
            OrderHistory::ACTOR_STAFF,
            3,
            'Админов А.А.',
            'Создан',
            'Отменён'
        );

        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'canceled',
            'actor_id' => 3,
        ]);
    }
}
