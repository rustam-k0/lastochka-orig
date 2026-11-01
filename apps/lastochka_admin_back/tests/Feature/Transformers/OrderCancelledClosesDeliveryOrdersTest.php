<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MID-243: finishDeliveryIfNeeded закрывал назначения курьеров ТОЛЬКО штампом
 * finished_at, оставляя активный status_id (1/2/3). delivery_back считает
 * занятость курьера по status_id — курьер оставался «На заказе» навсегда.
 * Теперь при закрытии проставляется и терминальный status_id:
 * CANCELLED-заказ → DELIVERY_CANCELED, FINISHED-заказ → DELIVERY_FINISHED.
 */
class OrderCancelledClosesDeliveryOrdersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    private function persistedOrderId(int $statusId = Status::ORDER_ASSEMBLED): int
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'occdo-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]))->id;
    }

    /** Рейс + назначение. Возвращает id строки delivery_orders. */
    private function insertAssignment(int $orderId, array $attrs = []): int
    {
        $deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => \DB::table('users')->insertGetId([
                'name' => 'Курьер', 'surname' => 'Хвостовой ' . uniqid(),
                'password' => 'test',
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return \DB::table('delivery_orders')->insertGetId(array_merge([
            'order_id' => $orderId, 'delivery_id' => $deliveryId,
            'status_id' => Status::DELIVERY_WAIT_TO_START, 'priority' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    public function test_cancelled_event_sets_terminal_status_on_active_assignments(): void
    {
        $orderId = $this->persistedOrderId();
        $waitingId = $this->insertAssignment($orderId);
        $arrivedId = $this->insertAssignment($orderId, ['status_id' => Status::DELIVERY_ARRIVED]);

        $order = Order::findOrFail($orderId);
        (new OrderEventTransformer)->processCancelledEvent($order, ['event_id' => 'occdo-1']);

        foreach ([$waitingId, $arrivedId] as $id) {
            $assignment = DeliveryOrder::withTrashed()->findOrFail($id);
            $this->assertSame(Status::DELIVERY_CANCELED, (int) $assignment->status_id);
            $this->assertNotNull($assignment->finished_at);
        }
    }

    public function test_cancelled_event_finishes_delivery_when_all_assignments_closed(): void
    {
        $orderId = $this->persistedOrderId();
        $doId = $this->insertAssignment($orderId);
        $deliveryId = (int) \DB::table('delivery_orders')->where('id', $doId)->value('delivery_id');

        (new OrderEventTransformer)->processCancelledEvent(Order::findOrFail($orderId), ['event_id' => 'occdo-2']);

        $this->assertNotNull(\DB::table('deliveries')->where('id', $deliveryId)->value('finished_at'));
    }

    public function test_terminal_assignment_without_finished_at_keeps_its_status(): void
    {
        // Аномалия: назначение уже в терминальном статусе, но без штампа времени.
        // Закрытие обязано проставить finished_at, НЕ перетирая FINISHED → CANCELED.
        $orderId = $this->persistedOrderId();
        $doId = $this->insertAssignment($orderId, ['status_id' => Status::DELIVERY_FINISHED]);

        (new OrderEventTransformer)->processCancelledEvent(Order::findOrFail($orderId), ['event_id' => 'occdo-3']);

        $assignment = DeliveryOrder::withTrashed()->findOrFail($doId);
        $this->assertSame(Status::DELIVERY_FINISHED, (int) $assignment->status_id);
        $this->assertNotNull($assignment->finished_at);
    }
}
