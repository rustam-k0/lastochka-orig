<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Actions\Order\CancelDraftOrderAction;
use App\Actions\Order\FinalizeDraftOrderAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Log;

/**
 * Приводит черновик заказа в соответствие с реальным состоянием платежа
 * (модуль draft_orders_until_payment).
 *
 * Штатный путь подтверждения — вебхук `payment.waiting_for_capture`. Этот
 * сервис — страховка на два случая, в которых вебхука недостаточно:
 *  - клиент вернулся с ЮKassa раньше вебхука и поллит заказ (иначе он видел бы
 *    «ожидает оплаты» по уже оплаченному заказу);
 *  - вебхук не доехал вовсе, и черновик доживает до TTL — отменять его, не
 *    спросив шлюз, нельзя: деньги могут быть захолдированы.
 *
 * Источник правды — ЮKassa: локальная транзакция считается достаточной, только
 * если холд уже подтверждён.
 */
final class DraftOrderPaymentSync
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly FinalizeDraftOrderAction $finalizeDraft,
        private readonly CancelDraftOrderAction $cancelDraft,
    ) {}

    /**
     * Синхронизировать один черновик.
     *
     * @param  bool  $cancelExpired  отменять черновик с истёкшим TTL, если оплата
     *                               так и не подтвердилась (режим крона)
     */
    public function sync(Order $order, bool $cancelExpired = false): Order
    {
        if ($order->status !== OrderStatus::Draft) {
            return $order;
        }

        $hold = $this->latestHold($order);

        // Холда нет вовсе (createHold упал после создания заказа) — подтверждать
        // нечего, ждать нечего: заказ висит с зарезервированным товаром.
        if (! $hold || ! $hold->yookassa_payment_id) {
            return $this->cancelIfExpired($order, $cancelExpired, 'hold_missing');
        }

        if ($hold->status === PaymentTransactionStatus::Succeeded) {
            return $this->finalizeDraft->execute($order);
        }

        if ($hold->status === PaymentTransactionStatus::Canceled) {
            return $this->cancelDraft->execute($order, 'payment_canceled');
        }

        try {
            $info = $this->paymentService->getPaymentState($hold->yookassa_payment_id);
        } catch (\Throwable $e) {
            Log::warning('Draft order payment state request failed', [
                'order_id' => $order->id,
                'payment_id' => $hold->yookassa_payment_id,
                'error' => $e->getMessage(),
            ]);

            // Шлюз недоступен: молча отменять нельзя — вдруг деньги захолдированы.
            // Черновик доживёт до следующего прогона крона.
            return $order;
        }

        return match ($info->status) {
            // Холд подтверждён: догоняем пропущенный вебхук — сохраняем карту,
            // held_amount и статус транзакции, затем финализируем заказ.
            'waiting_for_capture', 'succeeded' => $this->confirm($order, $hold->yookassa_payment_id),
            'canceled' => $this->decline($order, $hold->yookassa_payment_id),
            // pending — клиент всё ещё на странице оплаты (или бросил её).
            default => $this->cancelIfExpired($order, $cancelExpired, 'payment_pending'),
        };
    }

    private function confirm(Order $order, string $paymentId): Order
    {
        $this->paymentService->handleHoldSucceeded($paymentId);

        return $this->finalizeDraft->execute($order->fresh() ?? $order);
    }

    private function decline(Order $order, string $paymentId): Order
    {
        $this->paymentService->handleHoldCanceled($paymentId);

        return $this->cancelDraft->execute($order->fresh() ?? $order, 'payment_canceled');
    }

    private function cancelIfExpired(Order $order, bool $cancelExpired, string $reason): Order
    {
        if (! $cancelExpired || ! $this->isExpired($order)) {
            return $order;
        }

        return $this->cancelDraft->execute($order, $reason);
    }

    private function isExpired(Order $order): bool
    {
        return $order->awaiting_payment_until !== null
            && $order->awaiting_payment_until->isPast();
    }

    private function latestHold(Order $order): ?PaymentTransaction
    {
        return $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->latest('id')
            ->first();
    }
}
