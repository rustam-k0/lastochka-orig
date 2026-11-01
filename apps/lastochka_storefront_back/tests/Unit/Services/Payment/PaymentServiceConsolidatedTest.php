<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Тесты для consolidated-режима PaymentService (marketplace).
 *
 * Покрывает M.9=a (один hold на totalAmount) и M.10=b (consolidated refund).
 * См. docs/design-analysis/marketplace-model.md §5.1.
 *
 * Инвариант I-3: существующие методы createHold/capturePayment/refundPayment/cancelHold
 * НЕ затрагиваются — только новые ...ClientOrder / ...Consolidated методы.
 */
final class PaymentServiceConsolidatedTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->service = $this->app->make(PaymentService::class);
    }

    public function test_create_consolidated_hold_passes_return_url_to_gateway(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        $order = Order::factory()->for($user)->for($store)->create([
            'client_order_id' => (string) Str::uuid(),
            'total' => 300,
            'payment_type' => PaymentType::Cash,
        ]);

        $this->service->createConsolidatedHold(
            orders: [$order],
            totalAmount: 300.0,
            clientOrderId: (string) $order->client_order_id,
            returnUrl: 'https://store.example.ru/client-orders/1',
        );

        $hold = collect($this->gateway->calls)->firstWhere('method', 'hold');

        $this->assertSame('https://store.example.ru/client-orders/1', $hold['args']['returnUrl']);
    }

    public function test_create_consolidated_hold_creates_single_payment_transaction_with_client_order_id(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
            'payment_type' => PaymentType::Cash,
        ]);
        $order2 = Order::factory()->for($user)->for($store2)->create([
            'client_order_id' => $clientOrderId,
            'total' => 700,
            'payment_type' => PaymentType::Cash,
        ]);

        $totalAmount = 1100.0;

        $result = $this->service->createConsolidatedHold(
            orders: [$order1, $order2],
            totalAmount: $totalAmount,
            clientOrderId: $clientOrderId,
            paymentMethodId: null,
        );

        $this->assertTrue($result->success, 'hold result must be success');
        $this->assertNotNull($result->paymentId);
        $this->assertNotNull($result->confirmationUrl, 'confirmationUrl is required for UI redirect');

        // Один вызов gateway->hold() на суммарную сумму
        $holdCalls = $this->gateway->callsFor('hold');
        $this->assertCount(1, $holdCalls);
        $this->assertSame($totalAmount, $holdCalls[0]['args']['orderTotal']);

        // Одна запись PaymentTransaction с client_order_id, order_id=null, amount=total
        $transactions = PaymentTransaction::all();
        $this->assertCount(1, $transactions);
        $tx = $transactions->first();
        $this->assertNull($tx->order_id, 'consolidated hold не должен привязываться к одному sub-order');
        $this->assertSame($clientOrderId, (string) $tx->client_order_id);
        $this->assertSame(PaymentTransactionType::Hold, $tx->type);
        $this->assertSame(PaymentTransactionStatus::Pending, $tx->status);
        $this->assertSame($totalAmount, (float) $tx->amount);
        $this->assertSame($result->paymentId, $tx->yookassa_payment_id);
    }

    public function test_create_consolidated_hold_updates_sub_orders_with_payment_type_card_and_held_amount(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $card = PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => 'pm_fake_123',
            'card_type' => 'visa',
            'last_four' => '0000',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'is_default' => true,
        ]);

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
            'payment_type' => PaymentType::Cash,
        ]);
        $order2 = Order::factory()->for($user)->for($store2)->create([
            'client_order_id' => $clientOrderId,
            'total' => 700,
            'payment_type' => PaymentType::Cash,
        ]);

        $this->service->createConsolidatedHold(
            orders: [$order1, $order2],
            totalAmount: 1100.0,
            clientOrderId: $clientOrderId,
            paymentMethodId: $card->payment_method_id,
        );

        $order1->refresh();
        $order2->refresh();

        $this->assertSame(PaymentType::Card, $order1->payment_type);
        $this->assertSame(PaymentType::Card, $order2->payment_type);

        $this->assertSame($card->id, $order1->payment_card_id);
        $this->assertSame($card->id, $order2->payment_card_id);

        // Каждый sub-order получает свою долю от totalAmount
        $this->assertSame('400.00', (string) $order1->held_amount);
        $this->assertSame('700.00', (string) $order2->held_amount);
    }

    public function test_create_consolidated_hold_returns_failure_without_creating_transaction_when_gateway_fails(): void
    {
        $this->gateway->holdShouldFail = true;

        $user = User::factory()->create();
        $store1 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
        ]);

        $result = $this->service->createConsolidatedHold(
            orders: [$order1],
            totalAmount: 400.0,
            clientOrderId: $clientOrderId,
        );

        $this->assertFalse($result->success);

        // Failed hold записывается как Canceled для audit trail
        $tx = PaymentTransaction::where('client_order_id', $clientOrderId)->first();
        $this->assertNotNull($tx);
        $this->assertSame(PaymentTransactionStatus::Canceled, $tx->status);

        // Sub-order не должен получить payment_type=card при неудаче
        $order1->refresh();
        $this->assertNotSame(PaymentType::Card, $order1->payment_type);
    }

    public function test_capture_client_order_marks_all_sub_orders_paid_and_creates_single_capture_transaction(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();
        $store2 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
        ]);
        $order2 = Order::factory()->for($user)->for($store2)->create([
            'client_order_id' => $clientOrderId,
            'total' => 700,
        ]);

        // Создаём hold через service (pending → succeeded)
        $hold = $this->service->createConsolidatedHold(
            orders: [$order1, $order2],
            totalAmount: 1100.0,
            clientOrderId: $clientOrderId,
        );

        PaymentTransaction::where('yookassa_payment_id', $hold->paymentId)
            ->update(['status' => PaymentTransactionStatus::Succeeded]);

        $this->gateway->calls = [];

        $result = $this->service->captureClientOrder($clientOrderId, 1100.0);

        $this->assertTrue($result->success);

        $captureCalls = $this->gateway->callsFor('capture');
        $this->assertCount(1, $captureCalls, 'должен быть один capture вызов');
        $this->assertSame(1100.0, $captureCalls[0]['args']['amount']);
        $this->assertSame($hold->paymentId, $captureCalls[0]['args']['paymentId']);

        $captureTransactions = PaymentTransaction::where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Capture)
            ->get();
        $this->assertCount(1, $captureTransactions);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $captureTransactions->first()->status);

        $order1->refresh();
        $order2->refresh();
        $this->assertTrue($order1->is_paid);
        $this->assertTrue($order2->is_paid);
        $this->assertSame('400.00', (string) $order1->captured_amount);
        $this->assertSame('700.00', (string) $order2->captured_amount);
    }

    public function test_capture_client_order_returns_failure_when_no_active_hold(): void
    {
        $result = $this->service->captureClientOrder('nonexistent-client-order', 100.0);

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->gateway->callsFor('capture'));
    }

    public function test_cancel_consolidated_hold_calls_gateway_and_marks_transaction_canceled(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 400,
        ]);

        $hold = $this->service->createConsolidatedHold(
            orders: [$order1],
            totalAmount: 400.0,
            clientOrderId: $clientOrderId,
        );

        PaymentTransaction::where('yookassa_payment_id', $hold->paymentId)
            ->update(['status' => PaymentTransactionStatus::Succeeded]);

        $this->gateway->calls = [];

        $result = $this->service->cancelConsolidatedHold($clientOrderId);

        $this->assertTrue($result->success);
        $this->assertCount(1, $this->gateway->callsFor('cancel'));

        $tx = PaymentTransaction::where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Hold)
            ->first();
        $this->assertSame(PaymentTransactionStatus::Canceled, $tx->status);
    }

    public function test_cancel_consolidated_hold_is_noop_when_no_hold_exists(): void
    {
        $result = $this->service->cancelConsolidatedHold('nonexistent-client-order');

        $this->assertTrue($result->success);
        $this->assertCount(0, $this->gateway->callsFor('cancel'));
    }

    public function test_refund_client_order_creates_refund_transaction_and_calls_gateway(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 500,
        ]);

        $hold = $this->service->createConsolidatedHold(
            orders: [$order1],
            totalAmount: 500.0,
            clientOrderId: $clientOrderId,
        );
        PaymentTransaction::where('yookassa_payment_id', $hold->paymentId)
            ->update(['status' => PaymentTransactionStatus::Succeeded]);
        $this->service->captureClientOrder($clientOrderId, 500.0);
        $this->gateway->calls = [];

        $result = $this->service->refundClientOrder($clientOrderId, 200.0);

        $this->assertTrue($result->success);
        $refundCalls = $this->gateway->callsFor('refund');
        $this->assertCount(1, $refundCalls);
        $this->assertSame(200.0, $refundCalls[0]['args']['amount']);

        $refundTx = PaymentTransaction::where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Refund)
            ->first();
        $this->assertNotNull($refundTx);
        $this->assertSame(200.0, (float) $refundTx->amount);
        $this->assertNotNull($refundTx->yookassa_refund_id);
    }

    public function test_refund_client_order_rejects_amount_exceeding_captured(): void
    {
        $user = User::factory()->create();
        $store1 = Store::factory()->create();

        $clientOrderId = (string) Str::uuid();

        $order1 = Order::factory()->for($user)->for($store1)->create([
            'client_order_id' => $clientOrderId,
            'total' => 500,
        ]);

        $hold = $this->service->createConsolidatedHold(
            orders: [$order1],
            totalAmount: 500.0,
            clientOrderId: $clientOrderId,
        );
        PaymentTransaction::where('yookassa_payment_id', $hold->paymentId)
            ->update(['status' => PaymentTransactionStatus::Succeeded]);
        $this->service->captureClientOrder($clientOrderId, 500.0);

        $this->gateway->calls = [];
        $result = $this->service->refundClientOrder($clientOrderId, 600.0);

        $this->assertFalse($result->success);
        $this->assertCount(0, $this->gateway->callsFor('refund'));
    }
}
