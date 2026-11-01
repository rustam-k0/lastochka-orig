<?php

namespace Tests\Feature\Models;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryLogTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrder(): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::create([
            'uuid' => (string) random_int(100000, 999999),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]);
    }

    public function test_log_creates_order_history_row_with_all_fields(): void
    {
        $order = $this->makeOrder();

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_STATUS_CHANGED,
            OrderHistory::ACTOR_STAFF,
            42,
            'Иванов И.И.',
            'Создан',
            'В сборке',
            'тестовый комментарий'
        );

        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'status_changed',
            'actor_type' => 'staff',
            'actor_id' => 42,
            'actor_name' => 'Иванов И.И.',
            'old_value' => 'Создан',
            'new_value' => 'В сборке',
            'comment' => 'тестовый комментарий',
        ]);
    }

    public function test_log_allows_null_actor_id_and_comment_for_system_actions(): void
    {
        $order = $this->makeOrder();

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_PAYMENT_FIXED,
            OrderHistory::ACTOR_SYSTEM,
            null,
            'Оплата',
            'Не оплачен',
            'Оплачен'
        );

        $this->assertDatabaseHas('order_history', [
            'order_id' => $order->id,
            'action' => 'payment_fixed',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_name' => 'Оплата',
            'comment' => null,
        ]);
    }

    public function test_multiple_log_entries_for_same_order_are_ordered_by_created_at(): void
    {
        $order = $this->makeOrder();

        OrderHistory::log($order->id, OrderHistory::ACTION_CREATED, OrderHistory::ACTOR_CLIENT, null, 'Клиент', null, null);
        OrderHistory::log($order->id, OrderHistory::ACTION_STATUS_CHANGED, OrderHistory::ACTOR_STAFF, 1, 'Сборщиков С.С.', 'Создан', 'В сборке');

        $rows = OrderHistory::where('order_id', $order->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('created', $rows[0]->action);
        $this->assertSame('status_changed', $rows[1]->action);
    }
}
