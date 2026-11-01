<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\DTOs\Payment\PaymentInfo;
use App\Models\Order;
use App\Services\Payment\MockPaymentGateway;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Декоратор шлюза для mock-режима (YOOKASSA_MOCK_MODE).
 *
 * Запросы в ЮKassa уходят реально (тестовый магазин, клиент проходит форму
 * оплаты), но ответ нормализуется так, будто подтверждение уже получено:
 * вебхука на стенде нет и ждать его нечего.
 *
 * Граница ответственности: mock компенсирует ОТСУТСТВИЕ вебхука, а не отказ
 * шлюза. Провалившийся hold остаётся провалившимся — иначе заказ считался бы
 * оплаченным без единого платежа в ЮKassa.
 */
final class MockPaymentGatewayTest extends TestCase
{
    private FakePaymentGateway $inner;

    private MockPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inner = new FakePaymentGateway;
        $this->gateway = new MockPaymentGateway($this->inner);
    }

    private function order(float $total = 500): Order
    {
        $order = new Order;
        $order->total = $total;

        return $order;
    }

    // ==================== hold ====================

    public function test_pending_hold_is_reported_as_confirmed(): void
    {
        $this->inner->holdStatus = 'pending';

        $result = $this->gateway->hold($this->order());

        $this->assertTrue($result->success);
        $this->assertSame('waiting_for_capture', $result->status);
    }

    public function test_hold_keeps_confirmation_url_so_client_still_sees_payment_form(): void
    {
        $this->inner->holdStatus = 'pending';

        $result = $this->gateway->hold($this->order());

        $this->assertSame($this->inner->confirmationUrl, $result->confirmationUrl);
        $this->assertCount(1, $this->inner->callsFor('hold'), 'запрос в ЮKassa должен уйти реально');
    }

    public function test_hold_failure_is_not_masked(): void
    {
        $this->inner->holdShouldFail = true;
        $this->inner->holdDeclineReason = 'insufficient_funds';

        $result = $this->gateway->hold($this->order());

        $this->assertFalse($result->success, 'отказ шлюза — не то, что чинит mock-режим');
        $this->assertSame('insufficient_funds', $result->declineReason);
    }

    // ==================== capture / charge / cancel ====================

    public function test_failed_capture_is_treated_as_success(): void
    {
        $this->inner->captureShouldFail = true;

        $result = $this->gateway->capture('pay_1', 500);

        $this->assertTrue($result->success);
        $this->assertSame('succeeded', $result->status);
        $this->assertSame('pay_1', $result->paymentId);
        $this->assertNull($result->errorMessage);
    }

    public function test_successful_capture_is_passed_through(): void
    {
        $result = $this->gateway->capture('pay_1', 500);

        $this->assertTrue($result->success);
        $this->assertSame('pay_1', $result->paymentId);
        $this->assertCount(1, $this->inner->callsFor('capture'));
    }

    public function test_failed_charge_is_treated_as_success(): void
    {
        $this->inner->chargeShouldFail = true;

        $result = $this->gateway->charge($this->order(), 120, 'pm_1');

        $this->assertTrue($result->success);
        $this->assertSame('succeeded', $result->status);
        $this->assertNotNull($result->paymentId);
    }

    public function test_failed_cancel_is_treated_as_success(): void
    {
        $this->inner->cancelShouldFail = true;

        $result = $this->gateway->cancel('pay_1');

        $this->assertTrue($result->success);
        $this->assertSame('canceled', $result->status);
    }

    // ==================== refund ====================

    public function test_failed_refund_gets_synthetic_refund_id(): void
    {
        $this->inner->refundShouldFail = true;

        $result = $this->gateway->refund('pay_1', 200);

        $this->assertTrue($result->success);
        $this->assertSame('succeeded', $result->status);
        $this->assertNotNull($result->refundId, 'без refund_id транзакцию возврата не с чем связать');
        $this->assertStringStartsWith('mock-refund-', $result->refundId);
    }

    public function test_successful_refund_keeps_real_refund_id(): void
    {
        $result = $this->gateway->refund('pay_1', 200);

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('rf_test_', (string) $result->refundId);
    }

    // ==================== getPaymentInfo ====================

    public function test_pending_payment_info_is_reported_as_confirmed(): void
    {
        $this->inner->paymentInfo['pay_1'] = new PaymentInfo(
            paymentId: 'pay_1',
            status: 'pending',
            amount: '500.00',
            currency: 'RUB',
        );

        $info = $this->gateway->getPaymentInfo('pay_1');

        $this->assertSame('waiting_for_capture', $info->status);
        $this->assertSame('500.00', $info->amount);
    }

    public function test_unknown_payment_info_is_reported_as_confirmed(): void
    {
        // status='unknown' отдаёт YooKassaService, когда GET /payments упал.
        $this->inner->paymentInfo['pay_1'] = new PaymentInfo(
            paymentId: 'pay_1',
            status: 'unknown',
            amount: '0',
            currency: 'RUB',
        );

        $this->assertSame('waiting_for_capture', $this->gateway->getPaymentInfo('pay_1')->status);
    }

    public function test_payment_info_failure_is_replaced_with_confirmed_stub(): void
    {
        $this->inner->paymentInfoShouldThrow = true;

        $info = $this->gateway->getPaymentInfo('pay_1');

        $this->assertSame('pay_1', $info->paymentId);
        $this->assertSame('waiting_for_capture', $info->status);
    }

    public function test_canceled_payment_info_is_not_masked(): void
    {
        $this->inner->paymentInfo['pay_1'] = new PaymentInfo(
            paymentId: 'pay_1',
            status: 'canceled',
            amount: '500.00',
            currency: 'RUB',
        );

        $this->assertSame('canceled', $this->gateway->getPaymentInfo('pay_1')->status);
    }

    public function test_succeeded_payment_info_is_passed_through(): void
    {
        $this->inner->paymentInfo['pay_1'] = new PaymentInfo(
            paymentId: 'pay_1',
            status: 'succeeded',
            amount: '500.00',
            currency: 'RUB',
            paymentMethodId: 'pm_1',
            cardType: 'MasterCard',
            lastFour: '4444',
            paid: true,
        );

        $info = $this->gateway->getPaymentInfo('pay_1');

        $this->assertSame('succeeded', $info->status);
        $this->assertSame('pm_1', $info->paymentMethodId);
        $this->assertSame('4444', $info->lastFour);
        $this->assertTrue($info->paid);
    }

    // ==================== чеки ====================

    public function test_receipts_are_passed_through_untouched(): void
    {
        $this->inner->receiptsByPaymentId['pay_1'] = [['id' => 'rc_1', 'status' => 'succeeded']];

        $this->assertSame(
            [['id' => 'rc_1', 'status' => 'succeeded']],
            $this->gateway->getReceipts('pay_1'),
        );
    }

    public function test_independent_receipt_is_passed_through_untouched(): void
    {
        $response = $this->gateway->createIndependentReceipt(['type' => 'payment']);

        $this->assertSame(['id' => 'yk-ind-1', 'status' => 'pending'], $response);
        $this->assertCount(1, $this->inner->callsFor('createIndependentReceipt'));
    }
}
