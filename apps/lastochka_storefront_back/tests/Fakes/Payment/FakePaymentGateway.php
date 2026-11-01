<?php

declare(strict_types=1);

namespace Tests\Fakes\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\DTOs\Payment\PaymentResult;
use App\DTOs\Payment\ReceiptData;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;

/**
 * In-memory fake gateway для feature/unit-тестов PaymentService.
 * НЕ используется в продакшене — только в тестах.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<int, array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    public string $holdPaymentIdPrefix = 'pay_test_';

    public bool $holdShouldFail = false;

    /** Машинный код причины отказа, возвращаемый при holdShouldFail (как у ЮKassa). */
    public ?string $holdDeclineReason = null;

    /** Статус, который возвращает успешный hold (pending | waiting_for_capture). */
    public string $holdStatus = 'pending';

    public bool $captureShouldFail = false;

    /** Эмуляция обрыва соединения с ЮKassa на capture (cURL 28, GlitchTip 857). */
    public bool $captureShouldThrowConnection = false;

    public bool $chargeShouldFail = false;

    /** Эмуляция обрыва соединения с ЮKassa на доплате. */
    public bool $chargeShouldThrowConnection = false;

    /** Машинный код причины отказа, возвращаемый при chargeShouldFail (как у ЮKassa). */
    public ?string $chargeDeclineReason = null;

    public bool $cancelShouldFail = false;

    public bool $refundShouldFail = false;

    public string $confirmationUrl = 'https://yookassa.test/confirm/pay_test_anchor';

    /** @var array<string, PaymentInfo> */
    public array $paymentInfo = [];

    /** Эмуляция недоступности API при запросе состояния платежа. */
    public bool $paymentInfoShouldThrow = false;

    /**
     * Срабатывает один раз в начале getPaymentInfo(). Вебхук payment.succeeded
     * запрашивает платёж между ранней проверкой is_paid и блокировкой заказа —
     * хук позволяет вклинить в это окно параллельный процесс (capturePayment).
     */
    public ?\Closure $beforePaymentInfo = null;

    /** @var array<string, list<array<string, mixed>>> */
    public array $receiptsByPaymentId = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $receiptsByRefundId = [];

    private int $sequence = 0;

    public function hold(
        Order $order,
        ?string $paymentMethodId = null,
        ?ReceiptData $receipt = null,
        ?string $idempotenceKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $returnUrl = null,
    ): PaymentResult {
        $this->sequence++;
        $paymentId = $this->holdPaymentIdPrefix.$this->sequence;

        $this->calls[] = [
            'method' => 'hold',
            'args' => [
                'orderId' => $order->id,
                'orderTotal' => (float) $order->total,
                'paymentMethodId' => $paymentMethodId,
                'paymentId' => $paymentId,
                'receipt' => $receipt,
                'idempotenceKey' => $idempotenceKey,
                'description' => $description,
                'metadata' => $metadata,
                'returnUrl' => $returnUrl,
            ],
        ];

        if ($this->holdShouldFail) {
            return new PaymentResult(
                success: false,
                status: 'canceled',
                errorMessage: 'fake hold failure',
                declineReason: $this->holdDeclineReason,
            );
        }

        return new PaymentResult(
            success: true,
            paymentId: $paymentId,
            confirmationUrl: $this->confirmationUrl,
            status: $this->holdStatus,
            paymentMethodId: $paymentMethodId,
        );
    }

    public function capture(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult
    {
        $this->calls[] = [
            'method' => 'capture',
            'args' => ['paymentId' => $yookassaPaymentId, 'amount' => $amount, 'receipt' => $receipt],
        ];

        if ($this->captureShouldThrowConnection) {
            throw new ConnectionException('fake: Failed to connect to api.yookassa.ru port 443');
        }

        if ($this->captureShouldFail) {
            return new PaymentResult(success: false, paymentId: $yookassaPaymentId, errorMessage: 'fake capture failure');
        }

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: 'succeeded',
        );
    }

    public function cancel(string $yookassaPaymentId): PaymentResult
    {
        $this->calls[] = [
            'method' => 'cancel',
            'args' => ['paymentId' => $yookassaPaymentId],
        ];

        if ($this->cancelShouldFail) {
            return new PaymentResult(success: false, paymentId: $yookassaPaymentId, errorMessage: 'fake cancel failure');
        }

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: 'canceled',
        );
    }

    public function refund(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult
    {
        $refundId = 'rf_test_'.(++$this->sequence);

        $this->calls[] = [
            'method' => 'refund',
            'args' => ['paymentId' => $yookassaPaymentId, 'amount' => $amount, 'refundId' => $refundId, 'receipt' => $receipt],
        ];

        if ($this->refundShouldFail) {
            return new PaymentResult(success: false, paymentId: $yookassaPaymentId, errorMessage: 'fake refund failure');
        }

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: 'succeeded',
            refundId: $refundId,
        );
    }

    public function charge(Order $order, float $amount, string $paymentMethodId, ?ReceiptData $receipt = null): PaymentResult
    {
        $this->sequence++;
        $paymentId = 'pay_test_charge_'.$this->sequence;

        $this->calls[] = [
            'method' => 'charge',
            'args' => [
                'orderId' => $order->id,
                'amount' => $amount,
                'paymentMethodId' => $paymentMethodId,
                'paymentId' => $paymentId,
                'receipt' => $receipt,
            ],
        ];

        if ($this->chargeShouldThrowConnection) {
            throw new ConnectionException('fake: Failed to connect to api.yookassa.ru port 443');
        }

        if ($this->chargeShouldFail) {
            return new PaymentResult(
                success: false,
                paymentId: $paymentId,
                status: 'canceled',
                errorMessage: 'Недостаточно средств на карте',
                declineReason: $this->chargeDeclineReason,
            );
        }

        return new PaymentResult(
            success: true,
            paymentId: $paymentId,
            status: 'succeeded',
        );
    }

    public function getPaymentInfo(string $yookassaPaymentId): PaymentInfo
    {
        $this->calls[] = [
            'method' => 'getPaymentInfo',
            'args' => ['paymentId' => $yookassaPaymentId],
        ];

        if ($this->beforePaymentInfo !== null) {
            $hook = $this->beforePaymentInfo;
            $this->beforePaymentInfo = null;
            $hook();
        }

        if ($this->paymentInfoShouldThrow) {
            throw new \RuntimeException('fake connection error');
        }

        return $this->paymentInfo[$yookassaPaymentId] ?? new PaymentInfo(
            paymentId: $yookassaPaymentId,
            status: 'succeeded',
            amount: '0',
            currency: 'RUB',
        );
    }

    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array
    {
        $this->calls[] = [
            'method' => 'getReceipts',
            'args' => ['paymentId' => $paymentId, 'refundId' => $refundId],
        ];

        if ($paymentId !== null) {
            return $this->receiptsByPaymentId[$paymentId] ?? [];
        }
        if ($refundId !== null) {
            return $this->receiptsByRefundId[$refundId] ?? [];
        }

        return [];
    }

    public function createIndependentReceipt(array $payload): array
    {
        $this->calls[] = [
            'method' => 'createIndependentReceipt',
            'args' => ['payload' => $payload],
        ];

        return ['id' => 'yk-ind-1', 'status' => 'pending'];
    }

    public function callsFor(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['method'] === $method));
    }
}
