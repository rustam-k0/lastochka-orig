<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\IntegrationConfig;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\MockPaymentGateway;
use App\Services\Payment\PaymentService;
use App\Services\Payment\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Mock-режим оплаты (YOOKASSA_MOCK_MODE): тестовые доступы ЮKassa подключены,
 * но вебхук на стенд настроить нельзя. Всё, что штатно закрывается вебхуком,
 * закрывается сразу по факту запроса: hold считается подтверждённым, capture —
 * оплатой, refund — возвратом. Исключение — привязка карты: без введённой
 * клиентом карты подтверждать нечего.
 */
final class PaymentServiceMockModeTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $fake;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.yookassa.mock' => true]);

        $this->fake = new FakePaymentGateway;
        $this->fake->holdStatus = 'pending';

        $this->app->instance(PaymentGateway::class, new MockPaymentGateway($this->fake));
        $this->service = $this->app->make(PaymentService::class);
    }

    private function cardOrder(array $attributes = []): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        return Order::factory()->for($user)->for($store)->create(array_merge([
            'payment_type' => PaymentType::Card,
            'total' => 500,
        ], $attributes));
    }

    private function holdTransaction(Order $order): ?PaymentTransaction
    {
        return $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->latest('id')
            ->first();
    }

    /** Пересобрать сервис без mock-обёртки (режим выключен). */
    private function serviceWithoutMock(): PaymentService
    {
        config(['services.yookassa.mock' => false]);
        $this->app->instance(PaymentGateway::class, $this->fake);

        return $this->app->make(PaymentService::class);
    }

    // ==================== hold ====================

    public function test_hold_is_confirmed_immediately_without_webhook(): void
    {
        $order = $this->cardOrder();

        $this->service->createHold($order);

        $hold = $this->holdTransaction($order);
        $this->assertNotNull($hold);
        $this->assertSame(PaymentTransactionStatus::Succeeded, $hold->status);
        $this->assertSame(500.0, (float) $order->refresh()->held_amount);
    }

    public function test_hold_stays_pending_when_mock_disabled(): void
    {
        $service = $this->serviceWithoutMock();
        $order = $this->cardOrder();

        $service->createHold($order);

        $hold = $this->holdTransaction($order);
        $this->assertSame(PaymentTransactionStatus::Pending, $hold->status);
        $this->assertNull($order->refresh()->held_amount);
    }

    public function test_mock_is_ignored_for_live_credentials(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'payment',
            'driver' => 'yookassa',
            'credentials' => ['yookassa' => [
                'mode' => 'live',
                'live_shop_id' => '123',
                'live_secret_key' => 'secret',
            ]],
            'is_active' => true,
        ]);

        $order = $this->cardOrder();

        $this->service->createHold($order);

        $this->assertSame(
            PaymentTransactionStatus::Pending,
            $this->holdTransaction($order)->status,
            'на боевых кредитах mock-режим не должен считать заказ оплаченным',
        );
    }

    public function test_mock_applies_to_test_credentials(): void
    {
        IntegrationConfig::create([
            'integration_type' => 'payment',
            'driver' => 'yookassa',
            'credentials' => ['yookassa' => [
                'mode' => 'test',
                'test_shop_id' => '123',
                'test_secret_key' => 'secret',
            ]],
            'is_active' => true,
        ]);

        $order = $this->cardOrder();

        $this->service->createHold($order);

        $this->assertSame(PaymentTransactionStatus::Succeeded, $this->holdTransaction($order)->status);
    }

    public function test_hold_failure_leaves_transaction_canceled(): void
    {
        $this->fake->holdShouldFail = true;
        $order = $this->cardOrder();

        $result = $this->service->createHold($order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentTransactionStatus::Canceled, $this->holdTransaction($order)->status);
        $this->assertNull($order->refresh()->held_amount);
    }

    // ==================== capture ====================

    public function test_capture_marks_order_paid_even_if_gateway_rejects_it(): void
    {
        $order = $this->cardOrder();
        $this->service->createHold($order);

        $this->fake->captureShouldFail = true;

        $result = $this->service->capturePayment($order->refresh(), 500.0);

        $this->assertTrue($result->success);

        $capture = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Capture)
            ->first();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $capture->status);

        $order->refresh();
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame(500.0, (float) $order->captured_amount);
    }

    // ==================== refund ====================

    public function test_refund_is_confirmed_immediately_without_webhook(): void
    {
        $order = $this->cardOrder(['captured_amount' => 500, 'refunded_amount' => 0]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_captured_1',
        ]);

        $result = $this->service->refundPayment($order, 200.0);

        $this->assertTrue($result->success);

        $refund = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Refund)
            ->first();
        $this->assertSame(PaymentTransactionStatus::Succeeded, $refund->status);
        $this->assertSame(200.0, (float) $order->refresh()->refunded_amount);
    }

    public function test_refund_stays_pending_when_mock_disabled(): void
    {
        $service = $this->serviceWithoutMock();
        $order = $this->cardOrder(['captured_amount' => 500, 'refunded_amount' => 0]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 500,
            'yookassa_payment_id' => 'pay_captured_1',
        ]);

        $service->refundPayment($order, 200.0);

        $refund = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Refund)
            ->first();
        $this->assertSame(PaymentTransactionStatus::Pending, $refund->status);
        $this->assertSame(0.0, (float) $order->refresh()->refunded_amount);
    }

    // ==================== привязка карты ====================

    /**
     * Привязку mock не подтверждает (MID-314): в момент создания платежа клиент
     * ещё не ввёл карту, сохранять нечего. Карта досохраняется сверкой при
     * открытии списка карт, см. {@see \Tests\Feature\Payment\CardBindingSyncTest}.
     */
    public function test_bind_card_waits_for_client_to_enter_card(): void
    {
        $user = User::factory()->create();

        $this->fake->paymentInfo['pay_test_1'] = new PaymentInfo(
            paymentId: 'pay_test_1',
            status: 'pending',
            amount: '1.00',
            currency: 'RUB',
            paymentMethodId: 'pay_test_1',
        );

        $result = $this->service->bindCard($user);

        $this->assertTrue($result->success);
        $this->assertSame(0, PaymentCard::where('user_id', $user->id)->count());

        $hold = PaymentTransaction::where('yookassa_payment_id', 'pay_test_1')->first();
        $this->assertSame(
            PaymentTransactionStatus::Pending,
            $hold->status,
            'непройденная привязка не должна закрываться: иначе карту уже не сохранить',
        );
        $this->assertSame([], $this->fake->callsFor('cancel'));
    }

    // ==================== консолидированный холд (маркетплейс) ====================

    public function test_consolidated_hold_is_confirmed_immediately(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $clientOrderId = (string) Str::uuid();

        $order = Order::factory()->for($user)->for($store)->create([
            'client_order_id' => $clientOrderId,
            'total' => 300,
            'payment_type' => PaymentType::Cash,
        ]);

        $this->service->createConsolidatedHold(
            orders: [$order],
            totalAmount: 300.0,
            clientOrderId: $clientOrderId,
        );

        $hold = PaymentTransaction::where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Hold)
            ->first();

        $this->assertSame(
            PaymentTransactionStatus::Succeeded,
            $hold->status,
            'без подтверждённого холда captureClientOrder не найдёт что списывать',
        );

        $capture = $this->service->captureClientOrder($clientOrderId, 300.0);
        $this->assertTrue($capture->success);
    }

    // ==================== контейнер ====================

    public function test_container_wraps_gateway_when_mock_enabled(): void
    {
        $this->app->forgetInstance(PaymentGateway::class);
        config(['services.yookassa.mock' => true]);

        $this->assertInstanceOf(MockPaymentGateway::class, $this->app->make(PaymentGateway::class));
    }

    public function test_container_returns_plain_gateway_when_mock_disabled(): void
    {
        $this->app->forgetInstance(PaymentGateway::class);
        config(['services.yookassa.mock' => false]);

        $this->assertInstanceOf(YooKassaService::class, $this->app->make(PaymentGateway::class));
    }
}
