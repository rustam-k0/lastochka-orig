<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Payment\FakePaymentGateway;
use Tests\TestCase;

/**
 * Регрессия по заказу #345: сумма сборки (1914.69) превысила холд (1620),
 * поэтому при Assembled система сделала capture холда (1620, PID1) + досписание
 * разницы (294.69, PID2 — extra_charge). При отмене вернулось только 1620 —
 * досписание 294.69 потерялось.
 *
 * Две причины:
 *  (1) captured_amount затирался присваиванием (capturePayment/handlePaymentSucceeded
 *      пишут `= $amount`) поверх накопленного chargeExtra → поле занижалось;
 *  (2) refundPayment задваивал дубль-capture одного payment_id (252+253) в
 *      remainByPayment и из-за этого распределял весь возврат на PID1, не доходя
 *      до PID2.
 */
final class PaymentServiceExtraChargeRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->service = $this->app->make(PaymentService::class);
    }

    private function cardOrder(array $attributes): Order
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();

        return Order::factory()->for($user)->for($store)->create(array_merge([
            'payment_type' => PaymentType::Card,
            'total' => 1620,
        ], $attributes));
    }

    /**
     * Возврат при отмене должен распределяться по ОБОИМ платежам: остаток холда
     * по PID1 (1620) и досписание по PID2 (294.69) — суммарно вся захваченная
     * сумма. Дубль capture-записи по PID1 (как 252+253 у заказа #345) не должен
     * раздувать доступную к возврату сумму по PID1.
     */
    public function test_refund_distributes_across_hold_and_extra_charge_payments(): void
    {
        $order = $this->cardOrder([
            'captured_amount' => 1914.69,
            'refunded_amount' => 0,
            'held_amount' => 1620,
        ]);

        // capture холда (PID1)
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1620,
            'yookassa_payment_id' => 'pay_hold_pid1',
        ]);
        // дубль capture того же PID1 (гонка capturePayment + webhook payment.succeeded)
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1620,
            'yookassa_payment_id' => 'pay_hold_pid1',
        ]);
        // досписание (PID2, extra_charge)
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 294.69,
            'yookassa_payment_id' => 'pay_extra_pid2',
            'metadata' => ['extra_charge' => true],
        ]);

        $result = $this->service->refundOrReleaseHold($order);

        $this->assertTrue($result->success);

        $refundCalls = $this->gateway->callsFor('refund');

        $byPayment = [];
        foreach ($refundCalls as $call) {
            $byPayment[$call['args']['paymentId']] = ($byPayment[$call['args']['paymentId']] ?? 0)
                + $call['args']['amount'];
        }

        $this->assertEqualsWithDelta(1620.0, $byPayment['pay_hold_pid1'] ?? 0, 0.001, 'по PID1 должно вернуться ровно 1620 (не задвоенный дубль)');
        $this->assertEqualsWithDelta(294.69, $byPayment['pay_extra_pid2'] ?? 0, 0.001, 'досписание PID2 должно быть возвращено');

        $totalRefunded = array_sum($byPayment);
        $this->assertEqualsWithDelta(1914.69, $totalRefunded, 0.001, 'возврат должен покрыть всю захваченную сумму');
    }

    /**
     * capturePayment должен пересчитывать captured_amount из реальных succeeded
     * capture-транзакций, а не присваивать сумму холда поверх уже учтённого
     * досписания.
     */
    public function test_capture_payment_does_not_erase_prior_extra_charge(): void
    {
        $order = $this->cardOrder([
            'captured_amount' => 294.69,
            'held_amount' => 1620,
            'is_paid' => false,
        ]);

        // активный холд для capturePayment
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1620,
            'yookassa_payment_id' => 'pay_hold_pid1',
        ]);
        // досписание уже прошло отдельным платежом (PID2)
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 294.69,
            'yookassa_payment_id' => 'pay_extra_pid2',
            'metadata' => ['extra_charge' => true],
        ]);

        $this->service->capturePayment($order, 1620);

        $order->refresh();
        $this->assertEqualsWithDelta(1914.69, (float) $order->captured_amount, 0.001, 'capture не должен затирать досписание');
        $this->assertTrue((bool) $order->is_paid);
    }

    /**
     * Webhook payment.succeeded по холд-платежу не должен затирать captured_amount,
     * если по заказу уже прошло досписание (PID2). Должен учитывать обе захваченные
     * суммы.
     */
    public function test_payment_succeeded_webhook_does_not_erase_extra_charge(): void
    {
        $order = $this->cardOrder([
            'captured_amount' => 294.69,
            'held_amount' => 1620,
            'is_paid' => false,
        ]);

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 1620,
            'yookassa_payment_id' => 'pay_hold_pid1',
        ]);
        // досписание уже прошло
        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Capture,
            'status' => PaymentTransactionStatus::Succeeded,
            'amount' => 294.69,
            'yookassa_payment_id' => 'pay_extra_pid2',
            'metadata' => ['extra_charge' => true],
        ]);

        $this->gateway->paymentInfo['pay_hold_pid1'] = new PaymentInfo(
            paymentId: 'pay_hold_pid1',
            status: 'succeeded',
            amount: '1620.00',
            currency: 'RUB',
        );

        $this->service->handlePaymentSucceeded('pay_hold_pid1');

        $order->refresh();
        $this->assertEqualsWithDelta(1914.69, (float) $order->captured_amount, 0.001, 'webhook не должен затирать досписание');
        $this->assertTrue((bool) $order->is_paid);

        // capture по холд-платежу должен появиться, дубля по PID1 быть не должно
        $pid1Captures = PaymentTransaction::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('yookassa_payment_id', 'pay_hold_pid1')
            ->count();
        $this->assertSame(1, $pid1Captures);
    }
}
