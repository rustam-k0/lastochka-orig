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
use App\Models\StorefrontSetting;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Возврат денег при ВНЕШНЕЙ отмене заказа (оператор/курьерка/CRM по RabbitMQ),
 * OrderEventTransformer::beforeUpdate. Главный кейс — отмена уже собранного
 * оплаченного заказа, где деньги списаны (captured > 0).
 */
final class OrderCancelRefundTest extends TestCase
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

    private function enableMarketplaceMode(): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode(
                    ['marketplace_mode' => ['type' => 'select', 'value' => 'marketplace']],
                    JSON_UNESCAPED_UNICODE,
                ),
                'type' => 'json',
            ],
        );
        Cache::flush();
    }

    private function incomingCancel(Order $order): void
    {
        $transformer = new OrderEventTransformer;
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'status' => 'cancelled',
        ];
        $transformer->beforeUpdate($order, $eventData, ['status' => OrderStatus::Cancelled]);
    }

    public function test_external_cancel_of_captured_order_refunds_money(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembled,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'captured_amount' => 500,
            'held_amount' => 500,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_ext_cap',
        ]);

        $this->incomingCancel($order);

        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(500.0, $refundCalls[0]['args']['amount']);
        $this->assertCount(0, $this->gateway->callsFor('cancel'));
    }

    public function test_external_cancel_of_marketplace_suborder_skips_refund(): void
    {
        $this->enableMarketplaceMode();

        $store = Store::factory()->create(['code' => 'MSK_002']);
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Assembled,
            'payment_type' => PaymentType::Card,
            'total' => 500,
            'captured_amount' => 500,
            'held_amount' => 500,
            'client_order_id' => (string) Str::uuid(),
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_mp_cap',
        ]);

        $this->incomingCancel($order);

        $this->assertCount(0, $this->gateway->callsFor('refund'), 'marketplace sub-order: refund откладывается на консолидированную финализацию');
        $this->assertCount(0, $this->gateway->callsFor('cancel'));
    }
}
