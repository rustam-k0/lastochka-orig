<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class OrderCancelledHandlerTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer;
        Log::spy();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * OrderHistory::log() пишет реальную строку в БД с FK на orders.id —
     * мокнутый Order с произвольным id для неё не годится, нужен персистентный.
     */
    private function persistedOrderId(): int
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'ochp-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => Status::ORDER_CREATED,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]))->id;
    }

    public function test_cancelled_is_idempotent_when_status_already_cancelled(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 1;
        $order->status_id = Status::ORDER_CANCELLED;

        // withoutEvents не должен быть вызван — save БД не должен произойти
        $order->shouldNotReceive('forceFill');
        $order->shouldNotReceive('save');
        $order->shouldNotReceive('withoutEvents');

        $this->transformer->processCancelledEvent($order, ['event_id' => 'test-1']);

        $this->assertEquals(Status::ORDER_CANCELLED, $order->status_id);
    }

    public function test_cancelled_is_idempotent_when_status_already_finished(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 2;
        $order->status_id = Status::ORDER_FINISHED;

        $order->shouldNotReceive('forceFill');
        $order->shouldNotReceive('save');

        $this->transformer->processCancelledEvent($order, ['event_id' => 'test-2']);

        $this->assertEquals(Status::ORDER_FINISHED, $order->status_id);
    }

    public function test_cancelled_invokes_child_cascade(): void
    {
        // Регрессия LASTOCHKI-JAMI-10018: при отмене клиентом parent-заказа
        // через storefront → admin_back должен каскадить ORDER_CANCELLED на
        // child sub-orders, иначе сборщик видит подзаказ в ORDER_CREATED
        // и заходит на сборку уже отменённого заказа.
        //
        // Здесь проверяем именно интеграцию: что processCancelledEvent
        // дёргает cascadeCancellationToChildOrders с тем же $order.
        // Поведение самого каскада (childOrders → forceFill → finish helpers)
        // покрыто ручной верификацией на dev — мокать relation HasMany
        // через Mockery нельзя из-за return type на childOrders().

        $orderId = $this->persistedOrderId();

        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = $orderId;
        $order->status_id = Status::ORDER_PREPARING;
        $order->shouldReceive('withoutEvents')->andReturnUsing(fn ($cb) => $cb());
        $order->shouldReceive('forceFill')->with(['status_id' => Status::ORDER_CANCELLED])->andReturnSelf();
        $order->shouldReceive('save')->andReturnTrue();

        $transformer = new class extends OrderEventTransformer
        {
            public ?int $cascadedFor = null;

            protected function finishAssemblyIfNeeded(Model $order): void {}

            protected function finishDeliveryIfNeeded(Model $order): void {}

            protected function cascadeCancellationToChildOrders(Order $order): void
            {
                $this->cascadedFor = $order->id;
            }
        };

        $transformer->processCancelledEvent($order, ['event_id' => 'test-cascade']);

        $this->assertSame($orderId, $transformer->cascadedFor);
    }

    public function test_cancelled_does_not_cascade_when_already_terminal(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 460;
        $order->status_id = Status::ORDER_CANCELLED;

        $transformer = new class extends OrderEventTransformer
        {
            public ?int $cascadedFor = null;

            protected function cascadeCancellationToChildOrders(Order $order): void
            {
                $this->cascadedFor = $order->id;
            }
        };

        $transformer->processCancelledEvent($order, ['event_id' => 'test-terminal']);

        $this->assertNull($transformer->cascadedFor);
    }

    public function test_paid_is_idempotent_when_already_paid(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 3;
        $order->paid = true;

        $order->shouldNotReceive('forceFill');
        $order->shouldNotReceive('save');

        $this->transformer->processPaidEvent($order, [
            'event_id' => 'test-3',
            'payment_type' => 'card',
        ]);

        $this->assertTrue($order->paid);
    }

    /**
     * Platrum MEN-IN-DEV-11274: processPaidEvent должен проставлять
     * payment_status='paid' вместе с paid/paid_at, чтобы фильтр по статусу
     * оплаты в АП видел заказ как оплаченный.
     */
    public function test_paid_sets_payment_status_paid(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = $this->persistedOrderId();
        $order->paid = false;

        $order->shouldReceive('withoutEvents')->andReturnUsing(fn ($cb) => $cb());
        $order->shouldReceive('forceFill')->once()->with(Mockery::on(function ($updates) {
            return $updates['paid'] === true
                && $updates['payment_status'] === 'paid'
                && array_key_exists('paid_at', $updates);
        }))->andReturnSelf();
        $order->shouldReceive('save')->once()->andReturnTrue();

        $this->transformer->processPaidEvent($order, ['event_id' => 'test-4']);

        $this->addToAssertionCount(1); // Mockery::close() в tearDown проверит вызовы forceFill/save
    }

    /**
     * Platrum MEN-IN-DEV-11274: payment_failed — ЮKassa отменила hold
     * (недостаточно средств и т.п.). Не трогает paid/paid_at — заказ мог
     * быть оплачен раньше другим платежом.
     */
    public function test_payment_failed_sets_payment_status_failed(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = $this->persistedOrderId();

        $order->shouldReceive('withoutEvents')->once()->andReturnUsing(fn ($cb) => $cb());
        $order->shouldReceive('forceFill')->once()->with(['payment_status' => 'failed'])->andReturnSelf();
        $order->shouldReceive('save')->once()->andReturnTrue();

        $this->transformer->processPaymentFailedEvent($order, ['event_id' => 'test-5']);

        $this->addToAssertionCount(1);
    }

    /**
     * Platrum MEN-IN-DEV-11274: refunded — ЮKassa подтвердила возврат.
     */
    public function test_refunded_sets_payment_status_refunded(): void
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = $this->persistedOrderId();

        $order->shouldReceive('withoutEvents')->once()->andReturnUsing(fn ($cb) => $cb());
        $order->shouldReceive('forceFill')->once()->with(['payment_status' => 'refunded'])->andReturnSelf();
        $order->shouldReceive('save')->once()->andReturnTrue();

        $this->transformer->processRefundedEvent($order, ['event_id' => 'test-6']);

        $this->addToAssertionCount(1);
    }
}
