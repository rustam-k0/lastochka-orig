<?php

namespace Tests\Feature;

use App\Models\OrderHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryLogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_log_creates_order_history_row(): void
    {
        $clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = \DB::table('orders')->insertGetId([
            'uuid' => 'ohl-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $clientId,
            'status_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        OrderHistory::log(
            $orderId,
            OrderHistory::ACTION_COLLECTOR_ASSIGNED,
            OrderHistory::ACTOR_STAFF,
            7,
            'Сборщиков С.С.',
            null,
            'Сборщиков С.С.'
        );

        $this->assertDatabaseHas('order_history', [
            'order_id' => $orderId,
            'action' => 'collector_assigned',
            'actor_id' => 7,
            'new_value' => 'Сборщиков С.С.',
        ]);
    }
}
