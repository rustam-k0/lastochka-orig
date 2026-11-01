<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\DTOs\Payment\PaymentResult;
use App\DTOs\Payment\ReceiptData;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Шлюз для стенда, на котором подключены тестовые доступы ЮKassa, но вебхук
 * настроить нельзя (YOOKASSA_MOCK_MODE, {@see \App\Support\Payment\PaymentMockMode}).
 *
 * Запросы в ЮKassa уходят реально: платёж создаётся, виден в тестовом ЛК,
 * клиент так же проходит форму оплаты. Отличается только трактовка ответа —
 * подтверждения не ждём:
 *  - холд в статусе pending считаем подтверждённым (waiting_for_capture);
 *  - списание, досписание, снятие холда и возврат считаем прошедшими, даже
 *    если ЮKassa их отклонила: холд там висит неподтверждённым, и настоящий
 *    capture по нему невозможен в принципе.
 *
 * Чего mock НЕ делает: не маскирует провалившийся hold и отказ банка. Нет
 * платежа — нечего подтверждать, и заказ не должен выглядеть оплаченным.
 * Фискальные чеки (54-ФЗ) проходят насквозь: к вебхукам они отношения не имеют.
 */
final class MockPaymentGateway implements PaymentGateway
{
    /**
     * Статусы, которые в mock-режиме трактуются как подтверждённый холд.
     * `unknown` отдаёт {@see YooKassaService::getPaymentInfo()}, когда запрос
     * к API не удался — на стенде это не повод считать оплату неуспешной.
     */
    private const UNCONFIRMED_STATUSES = ['pending', 'unknown'];

    public function __construct(private readonly PaymentGateway $inner) {}

    public function hold(
        Order $order,
        ?string $paymentMethodId = null,
        ?ReceiptData $receipt = null,
        ?string $idempotenceKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $returnUrl = null,
    ): PaymentResult {
        $result = $this->inner->hold(
            $order,
            $paymentMethodId,
            $receipt,
            $idempotenceKey,
            $description,
            $metadata,
            $returnUrl,
        );

        // Отказ шлюза оставляем как есть: mock компенсирует отсутствие вебхука,
        // а не отсутствие платежа.
        if (! $result->success || ! $this->isUnconfirmed($result->status)) {
            return $result;
        }

        Log::info('YooKassa mock: hold treated as confirmed', [
            'order_id' => $order->id,
            'payment_id' => $result->paymentId,
            'real_status' => $result->status,
        ]);

        return new PaymentResult(
            success: true,
            paymentId: $result->paymentId,
            // Адрес формы оплаты сохраняем: клиент проходит её как обычно.
            confirmationUrl: $result->confirmationUrl,
            status: 'waiting_for_capture',
            paymentMethodId: $result->paymentMethodId,
        );
    }

    public function capture(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult
    {
        $result = $this->inner->capture($yookassaPaymentId, $amount, $receipt);

        if ($result->success) {
            return $result;
        }

        $this->logSuppressedFailure('capture', $result, [
            'payment_id' => $yookassaPaymentId,
            'amount' => $amount,
        ]);

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: 'succeeded',
        );
    }

    public function cancel(string $yookassaPaymentId): PaymentResult
    {
        $result = $this->inner->cancel($yookassaPaymentId);

        if ($result->success) {
            return $result;
        }

        $this->logSuppressedFailure('cancel', $result, ['payment_id' => $yookassaPaymentId]);

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: 'canceled',
        );
    }

    public function refund(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult
    {
        $result = $this->inner->refund($yookassaPaymentId, $amount, $receipt);

        if ($result->success) {
            return $result;
        }

        $this->logSuppressedFailure('refund', $result, [
            'payment_id' => $yookassaPaymentId,
            'amount' => $amount,
        ]);

        return new PaymentResult(
            success: true,
            paymentId: $yookassaPaymentId,
            status: 'succeeded',
            // Без идентификатора возврат не с чем связать: по нему ищется
            // транзакция при подтверждении (handleRefundSucceeded).
            refundId: 'mock-refund-'.Str::uuid()->toString(),
        );
    }

    public function charge(Order $order, float $amount, string $paymentMethodId, ?ReceiptData $receipt = null): PaymentResult
    {
        $result = $this->inner->charge($order, $amount, $paymentMethodId, $receipt);

        if ($result->success) {
            return $result;
        }

        $this->logSuppressedFailure('charge', $result, [
            'order_id' => $order->id,
            'amount' => $amount,
        ]);

        return new PaymentResult(
            success: true,
            paymentId: $result->paymentId ?? 'mock-payment-'.Str::uuid()->toString(),
            status: 'succeeded',
        );
    }

    public function getPaymentInfo(string $yookassaPaymentId): PaymentInfo
    {
        try {
            $info = $this->inner->getPaymentInfo($yookassaPaymentId);
        } catch (\Throwable $e) {
            // На стенде недоступность API не должна ронять оформление заказа:
            // считаем платёж подтверждённым, как и всё остальное в mock-режиме.
            Log::warning('YooKassa mock: payment info request failed, assuming confirmed', [
                'payment_id' => $yookassaPaymentId,
                'error' => $e->getMessage(),
            ]);

            return new PaymentInfo(
                paymentId: $yookassaPaymentId,
                status: 'waiting_for_capture',
                amount: '0',
                currency: 'RUB',
            );
        }

        if (! $this->isUnconfirmed($info->status)) {
            return $info;
        }

        return new PaymentInfo(
            paymentId: $info->paymentId,
            status: 'waiting_for_capture',
            amount: $info->amount,
            currency: $info->currency,
            paymentMethodId: $info->paymentMethodId,
            cardType: $info->cardType,
            lastFour: $info->lastFour,
            expiryMonth: $info->expiryMonth,
            expiryYear: $info->expiryYear,
            paid: $info->paid,
        );
    }

    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array
    {
        return $this->inner->getReceipts($paymentId, $refundId);
    }

    public function createIndependentReceipt(array $payload): array
    {
        return $this->inner->createIndependentReceipt($payload);
    }

    private function isUnconfirmed(?string $status): bool
    {
        return in_array((string) $status, self::UNCONFIRMED_STATUSES, true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logSuppressedFailure(string $operation, PaymentResult $result, array $context): void
    {
        Log::warning("YooKassa mock: {$operation} failed, treated as success", $context + [
            'error' => $result->errorMessage,
        ]);
    }
}
