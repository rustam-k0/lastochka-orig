<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * T4: admin_back проставляет orders.finished_at при переходе заказа в статус
 * «Доставлен» (ORDER_FINISHED) на стороне консьюмера order.updated / status_changed.
 *
 * Гейт по null (??=-семантика) покрывает echo-consume race трансформера:
 * повторное/устаревшее delivered-событие не должно сдвигать отметку завершения.
 */
class OrderFinishedAtConsumerTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer;
        Log::spy();
    }

    /**
     * Персистентный заказ с заданным статусом и (опционально) finished_at.
     * Реальная модель нужна, т.к. afterUpdate дёргает relation childOrders().
     */
    private function persistOrder(int $statusId, $finishedAt = null): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'fat-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => 2,
            'payment_type_id' => 1,
            'finished_at' => $finishedAt,
        ]));
    }

    public function test_after_update_sets_finished_at_when_status_finished_and_null(): void
    {
        $order = $this->persistOrder(Status::ORDER_FINISHED, null);

        $this->transformer->afterUpdate($order, ['event_id' => 'fin-1']);

        // in-memory модель синхронизирована
        $this->assertNotNull($order->finished_at);
        // и значение реально записано в БД
        $this->assertNotNull(Order::find($order->id)->finished_at);
    }

    public function test_after_update_does_not_overwrite_existing_finished_at(): void
    {
        // Регрессия echo-consume race: повторное delivered-событие приходит на
        // уже завершённый заказ — отметку завершения трогать нельзя.
        $past = now()->subDays(3)->startOfSecond();
        $order = $this->persistOrder(Status::ORDER_FINISHED, $past);

        $this->transformer->afterUpdate($order, ['event_id' => 'fin-2']);

        $reloaded = Order::find($order->id);
        $this->assertSame(
            $past->format('Y-m-d H:i:s'),
            $reloaded->finished_at->format('Y-m-d H:i:s')
        );
    }

    public function test_after_update_leaves_finished_at_null_for_non_finished_status(): void
    {
        $order = $this->persistOrder(Status::ORDER_IN_DELIVERY, null);

        $this->transformer->afterUpdate($order, ['event_id' => 'fin-3']);

        $this->assertNull($order->finished_at);
        $this->assertNull(Order::find($order->id)->finished_at);
    }

    public function test_after_create_sets_finished_at_when_created_finished(): void
    {
        // call-site afterCreate: заказ пришёл сразу в статусе «Доставлен».
        $order = $this->persistOrder(Status::ORDER_FINISHED, null);

        $this->transformer->afterCreate($order, [
            'event_id' => 'fin-5',
            'shop_code' => 'default',
        ]);

        $this->assertNotNull($order->finished_at);
        $this->assertNotNull(Order::find($order->id)->finished_at);
    }

    public function test_static_helper_gates_by_status_and_null(): void
    {
        // Прямой юнит на переиспользуемый Order::setFinishedAtIfNeeded
        // (общий источник для трансформера и CDEK-webhook'а).
        $inDelivery = $this->persistOrder(Status::ORDER_IN_DELIVERY, null);
        $this->assertFalse(Order::setFinishedAtIfNeeded($inDelivery));
        $this->assertNull(Order::find($inDelivery->id)->finished_at);

        $finished = $this->persistOrder(Status::ORDER_FINISHED, null);
        $this->assertTrue(Order::setFinishedAtIfNeeded($finished));
        $this->assertNotNull($finished->finished_at); // in-memory синхронизация
        $stamp = Order::find($finished->id)->finished_at;
        $this->assertNotNull($stamp);

        // Повторный вызов — false и отметку не двигает.
        $this->assertFalse(Order::setFinishedAtIfNeeded($finished));
        $this->assertSame(
            $stamp->format('Y-m-d H:i:s'),
            Order::find($finished->id)->finished_at->format('Y-m-d H:i:s')
        );
    }

    public function test_status_changed_to_delivered_sets_finished_at(): void
    {
        // Приёмка статуса из 1С (processStatusChangedEvent): переход в delivered
        // тоже фиксирует момент завершения.
        $order = $this->persistOrder(Status::ORDER_IN_DELIVERY, null);

        $this->transformer->processStatusChangedEvent($order, [
            'event_id' => 'fin-4',
            'new_status' => 'delivered',
        ]);

        $reloaded = Order::find($order->id);
        $this->assertSame(Status::ORDER_FINISHED, (int) $reloaded->status_id);
        $this->assertNotNull($reloaded->finished_at);
    }

    public function test_set_finished_at_if_needed_gate_via_subclass(): void
    {
        // Прямой юнит на сам гейт: статус не финальный → отметка не ставится;
        // финальный + null → ставится; повторный вызов не перезаписывает.
        $probe = new class extends OrderEventTransformer
        {
            public function callGate(Model $order): void
            {
                $this->setFinishedAtIfNeeded($order);
            }
        };

        $inDelivery = $this->persistOrder(Status::ORDER_IN_DELIVERY, null);
        $probe->callGate($inDelivery);
        $this->assertNull(Order::find($inDelivery->id)->finished_at);

        $finished = $this->persistOrder(Status::ORDER_FINISHED, null);
        $probe->callGate($finished);
        $firstStamp = Order::find($finished->id)->finished_at;
        $this->assertNotNull($firstStamp);

        // Повторный вызов не двигает отметку.
        $probe->callGate($finished);
        $this->assertSame(
            $firstStamp->format('Y-m-d H:i:s'),
            Order::find($finished->id)->finished_at->format('Y-m-d H:i:s')
        );
    }
}
