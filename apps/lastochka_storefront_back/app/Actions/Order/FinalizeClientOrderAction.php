<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Services\Order\ClientOrderStatusAggregator;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * P2.6 — Consolidated refund flow.
 *
 * Финализация marketplace client-order'а: когда все sub-orders достигли terminal state
 * (Delivered / Cancelled), посчитать consolidated capture/refund на разницу hold vs фактически доставлено.
 *
 * Решение M.10=b: НЕ делаем немедленный частичный refund при отмене одного sub-order'а.
 * Деньги блокированы до завершения всех остальных; в самом конце — единый расчёт.
 * См. docs/design-analysis/marketplace-model.md §5.1.
 *
 * Идемпотентность через `orders.client_order_finalized_at` — action может вызываться
 * из N мест (cancelSubOrder hook, afterUpdate delivered hook, manual script, webhook).
 * Повторные вызовы после первой успешной финализации — no-op.
 *
 * Зависимости:
 *   - {@see ClientOrderStatusAggregator} (P2.5) — консолидация статусов по "минимальному прогрессу"
 *   - {@see PaymentService} (P2.1) — методы captureClientOrder / cancelConsolidatedHold / refundClientOrder
 *
 * NB: Класс НЕ `final`, чтобы тесты могли подменить protected хуки do* — в main ветке storefront_back'а
 * PaymentService ещё не содержит consolidated-методов (они придут из feature/marketplace-p2-payment).
 * Это позволяет проверять бизнес-логику без прямого вызова несуществующих методов в worktree.
 */
class FinalizeClientOrderAction
{
    public function __construct(
        private readonly ClientOrderStatusAggregator $statusAggregator,
        private readonly PaymentService $paymentService,
    ) {
    }

    /**
     * Финализация клиентского заказа.
     *
     * Ничего не делает если:
     *   - sub-orders нет (orders->isEmpty)
     *   - уже финализирован (client_order_finalized_at != null — I-4 fail-safe / идемпотентность)
     *   - не все sub-orders в terminal state (Delivered / Cancelled)
     */
    public function execute(string $clientOrderId): void
    {
        DB::transaction(function () use ($clientOrderId): void {
            $orders = Order::query()
                ->where('client_order_id', $clientOrderId)
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return;
            }

            // I-4 fail-safe / идемпотентность: достаточно проверить первый sub-order,
            // т.к. финализация помечает все sub-orders одновременно.
            if ($orders->first()->client_order_finalized_at !== null) {
                return;
            }

            // Terminal-check: все sub-orders должны быть Delivered или Cancelled.
            // Иначе ещё рано — ждём следующего хука.
            $statuses = $orders
                ->map(static fn (Order $o): OrderStatus => $o->status instanceof OrderStatus
                    ? $o->status
                    : OrderStatus::from((string) $o->status))
                ->all();

            foreach ($statuses as $status) {
                if ($status !== OrderStatus::Delivered && $status !== OrderStatus::Cancelled) {
                    return;
                }
            }

            $consolidatedStatus = $this->statusAggregator->aggregate($statuses);

            $firstOrder = $orders->first();
            $paymentType = $firstOrder->payment_type instanceof PaymentType
                ? $firstOrder->payment_type
                : ($firstOrder->payment_type !== null
                    ? PaymentType::from((string) $firstOrder->payment_type)
                    : null);

            // Consolidated hold существует только при оплате картой.
            // Суммарный hold восстанавливаем из `orders.total` по всем sub-orders — это равно
            // консолидированному hold'у из {@see PaymentService::createConsolidatedHold},
            // который сохраняет per-sub-order `held_amount = (float) $order->total`.
            $totalHeld = round((float) $orders->sum('total'), 2);

            if ($paymentType === PaymentType::Card && $totalHeld > 0) {
                if ($consolidatedStatus === OrderStatus::Cancelled) {
                    // Все sub-orders cancelled → cancel hold полностью, capture/refund не нужны.
                    $this->doCancelConsolidatedHold($clientOrderId);
                } else {
                    // Часть или все delivered → capture на сумму доставленного, refund на разницу.
                    $deliveredOrders = $orders->filter(
                        static fn (Order $o): bool => ($o->status instanceof OrderStatus
                            ? $o->status
                            : OrderStatus::from((string) $o->status)) === OrderStatus::Delivered
                    );
                    $capturedSum = round((float) $deliveredOrders->sum('total'), 2);

                    if ($capturedSum > 0) {
                        $this->doCaptureClientOrder($clientOrderId, $capturedSum);
                    }

                    $refundAmount = round($totalHeld - $capturedSum, 2);

                    // Float arithmetic по деньгам: сравниваем через порог 0.01 вместо прямого > 0.
                    if ($refundAmount > 0.01) {
                        $this->doRefundClientOrder($clientOrderId, $refundAmount);
                    }
                }
            }

            // Пометить все sub-orders finalized — идемпотентная защита от повторных вызовов.
            Order::query()
                ->whereIn('id', $orders->pluck('id'))
                ->update(['client_order_finalized_at' => now()]);
        });
    }

    /**
     * Test hook: обёртка над `PaymentService::cancelConsolidatedHold`.
     */
    protected function doCancelConsolidatedHold(string $clientOrderId): void
    {
        $this->paymentService->cancelConsolidatedHold($clientOrderId);
    }

    /**
     * Test hook: обёртка над `PaymentService::captureClientOrder`.
     */
    protected function doCaptureClientOrder(string $clientOrderId, float $amount): void
    {
        $this->paymentService->captureClientOrder($clientOrderId, $amount);
    }

    /**
     * Test hook: обёртка над `PaymentService::refundClientOrder`.
     */
    protected function doRefundClientOrder(string $clientOrderId, float $amount): void
    {
        $this->paymentService->refundClientOrder($clientOrderId, $amount);
    }
}
