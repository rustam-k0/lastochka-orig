<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderHistoryPaymentAndCancelTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrder(array $extra = []): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create(array_merge([
            'uuid' => 'ohp-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
            'paid' => false,
        ], $extra)));
    }

    public function test_process_paid_event_logs_payment_fixed(): void
    {
        $order = $this->makeOrder();

        (new OrderEventTransformer())->processPaidEvent($order, ['event_id' => 'e1', 'payment_status' => 'paid']);

        $row = OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_PAYMENT_FIXED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Не оплачен', $row->old_value);
        $this->assertSame('Оплачен', $row->new_value);
    }

    public function test_process_paid_event_skipped_when_already_paid_does_not_log_again(): void
    {
        $order = $this->makeOrder(['paid' => true, 'payment_status' => 'paid']);

        (new OrderEventTransformer())->processPaidEvent($order, ['event_id' => 'e2']);

        $this->assertSame(
            0,
            OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_PAYMENT_FIXED)->count()
        );
    }

    public function test_process_payment_failed_event_logs_payment_fixed(): void
    {
        $order = $this->makeOrder(['payment_status' => 'pending']);

        (new OrderEventTransformer())->processPaymentFailedEvent($order, ['event_id' => 'e3']);

        $row = OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_PAYMENT_FIXED)->first();
        $this->assertNotNull($row);
        $this->assertSame('Не оплачен', $row->old_value);
        $this->assertSame('Ошибка оплаты', $row->new_value);
    }

    public function test_process_refunded_event_logs_payment_fixed(): void
    {
        $order = $this->makeOrder(['paid' => true, 'payment_status' => 'paid']);

        (new OrderEventTransformer())->processRefundedEvent($order, ['event_id' => 'e4']);

        $row = OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_PAYMENT_FIXED)->first();
        $this->assertNotNull($row);
        $this->assertSame('Оплачен', $row->old_value);
        $this->assertSame('Возврат', $row->new_value);
    }

    public function test_process_cancelled_event_logs_canceled_action_with_client_actor(): void
    {
        $order = $this->makeOrder(['status_id' => Status::ORDER_PREPARING]);

        (new OrderEventTransformer())->processCancelledEvent($order, ['event_id' => 'e5']);

        $row = OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_CANCELED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_CLIENT, $row->actor_type);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Отменён', $row->new_value);
    }

    public function test_process_cancelled_event_skipped_for_terminal_status_does_not_log(): void
    {
        $order = $this->makeOrder(['status_id' => Status::ORDER_FINISHED]);

        (new OrderEventTransformer())->processCancelledEvent($order, ['event_id' => 'e6']);

        $this->assertSame(
            0,
            OrderHistory::where('order_id', $order->id)->where('action', OrderHistory::ACTION_CANCELED)->count()
        );
    }

    public function test_process_cancelled_event_cascades_history_to_active_child_orders(): void
    {
        $order = $this->makeOrder(['status_id' => Status::ORDER_PREPARING]);
        $child = $this->makeOrder([
            'parent_order_id' => $order->id,
            'status_id' => Status::ORDER_PREPARING,
        ]);

        (new OrderEventTransformer())->processCancelledEvent($order, ['event_id' => 'e7']);

        $row = OrderHistory::where('order_id', $child->id)->where('action', OrderHistory::ACTION_CANCELED)->first();
        $this->assertNotNull($row);
        $this->assertSame(OrderHistory::ACTOR_SYSTEM, $row->actor_type);
        $this->assertSame('Заказ на сборке', $row->old_value);
        $this->assertSame('Отменён', $row->new_value);
        $this->assertSame('Отменён каскадно вместе с родительским заказом', $row->comment);
    }
}
