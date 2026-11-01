<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Internal;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\SendSurchargeRequiredNotification;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

final class SettleOrderPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.internal_api.token' => 'test-internal-token']);
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    // Фикстура адаптирована по образцу tests/Unit/Services/Payment/PaymentServiceSettleAssemblyTest.php:
    // brief использовал 'last4' (не существует в схеме) и не задавал items_count/expiry_month/expiry_year
    // (NOT NULL без дефолта) — здесь заполнены обязательные поля payment_cards/orders.
    private function heldOrder(float $held): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $card = PaymentCard::create([
            'user_id' => $user->id, 'payment_method_id' => 'pm_test',
            'card_type' => 'Visa', 'last_four' => '4444',
            'expiry_month' => '12', 'expiry_year' => '2030',
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'store_id' => $store->id,
            'status' => OrderStatus::Assembling, 'payment_type' => PaymentType::Card,
            'subtotal' => $held, 'total' => $held, 'held_amount' => $held,
            'items_count' => 1, 'shipping_method' => ShippingMethod::Courier,
            'payment_card_id' => $card->id,
        ]);
        PaymentTransaction::create([
            'order_id' => $order->id, 'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded, 'amount' => $held,
            'yookassa_payment_id' => 'pay_hold_1', 'payment_method_id' => 'pm_test',
        ]);

        return $order->fresh();
    }

    public function test_rejects_without_token(): void
    {
        $order = $this->heldOrder(5000);

        $this->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 5000])
            ->assertStatus(403);
    }

    public function test_settles_successfully(): void
    {
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 4500])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('payment_status', 'paid');
    }

    public function test_failed_surcharge_returns_402_with_details(): void
    {
        // Queue::fake — markAwaitingSurcharge диспатчит SendSurchargeRequiredNotification
        // (queue=sync в mendev → выполнился бы синхронно и упал на Firebase-кредах,
        // как в PaymentServiceSettleAssemblyTest::test_failed_extra_charge_...).
        Queue::fake([SendSurchargeRequiredNotification::class]);
        $this->gateway->chargeShouldFail = true;
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 6000])
            ->assertStatus(402)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('surcharge_amount', 1000);
    }

    public function test_validates_amount(): void
    {
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => -5])
            ->assertStatus(422);
    }

    public function test_capture_failure_after_extra_charge_returns_503(): void
    {
        $this->gateway->captureShouldFail = true;
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 6000])
            ->assertStatus(503)
            ->assertJsonPath('status', 'retry');
    }

    public function test_capture_connection_error_returns_503_and_sets_retry_deadline(): void
    {
        $this->gateway->captureShouldThrowConnection = true;
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 4500])
            ->assertStatus(503)
            ->assertJsonPath('status', 'retry');

        $fresh = $order->fresh();
        $this->assertNull($fresh->surcharge_amount);
        $this->assertNotNull($fresh->surcharge_expires_at);
        $this->assertFalse((bool) $fresh->is_paid);
    }

    public function test_charge_connection_error_returns_503_without_awaiting_surcharge(): void
    {
        $this->gateway->chargeShouldThrowConnection = true;
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 6000])
            ->assertStatus(503)
            ->assertJsonPath('status', 'retry');

        $fresh = $order->fresh();
        $this->assertNull($fresh->surcharge_amount);
        $this->assertNotNull($fresh->surcharge_expires_at);
        $this->assertSame(0, PaymentTransaction::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Capture)->count());
    }

    public function test_retry_after_connection_error_settles(): void
    {
        $this->gateway->captureShouldThrowConnection = true;
        $order = $this->heldOrder(5000);

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 4500])
            ->assertStatus(503);

        $this->gateway->captureShouldThrowConnection = false;

        $this->withHeader('X-Internal-Token', 'test-internal-token')
            ->postJson("/api/v1/internal/orders/{$order->id}/settle-payment", ['amount' => 4500])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertNull($order->fresh()->surcharge_expires_at);
    }
}
