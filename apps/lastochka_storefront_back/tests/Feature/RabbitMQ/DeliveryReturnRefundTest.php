<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Частичный рефанд при доставке с возвратом части позиций курьером (tg-14265).
 * delivery_back присылает order.updated со статусом delivered и уменьшенным
 * grand_total (delivered) → storefront возвращает разницу captured − delivered
 * для карты. Наличные и marketplace sub-order — без рефанда.
 */
final class DeliveryReturnRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Bus::fake([SendOrderStatusNotification::class]);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    private function incomingDelivered(Order $order, float $grandTotal): void
    {
        (new OrderEventTransformer)->afterUpdate($order, [
            'status' => 'delivered',
            'grand_total' => $grandTotal,
        ]);
    }

    public function test_partial_refund_on_delivered_with_reduced_total(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_R01']);
        $user = User::factory()->create();
        // Захвачено 300 на сборке, доставлено на 200 → рефанд 100.
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Delivered,
            'payment_type' => PaymentType::Card,
            'total' => 200,
            'captured_amount' => 300,
            'refunded_amount' => 0,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 300,
            'yookassa_payment_id' => 'pay_ret_cap',
        ]);

        $this->incomingDelivered($order, 200.0);

        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(100.0, $refundCalls[0]['args']['amount']);
    }

    public function test_no_refund_for_cash(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_R02']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Delivered,
            'payment_type' => PaymentType::Cash,
            'total' => 200,
            'captured_amount' => 0,
            'refunded_amount' => 0,
        ]);

        $this->incomingDelivered($order, 200.0);

        $this->assertCount(0, $this->gateway->callsFor('refund'));
    }

    public function test_no_refund_when_nothing_returned(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_R03']);
        $user = User::factory()->create();
        // Доставлено на полную захваченную сумму — рефанда нет.
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Delivered,
            'payment_type' => PaymentType::Card,
            'total' => 300,
            'captured_amount' => 300,
            'refunded_amount' => 0,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 300,
            'yookassa_payment_id' => 'pay_full_cap',
        ]);

        $this->incomingDelivered($order, 300.0);

        $this->assertCount(0, $this->gateway->callsFor('refund'));
    }
}
