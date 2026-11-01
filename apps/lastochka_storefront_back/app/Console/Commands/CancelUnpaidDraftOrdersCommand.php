<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Order\DraftOrderPaymentSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Закрывает черновики заказов с истёкшим сроком ожидания оплаты
 * (модуль draft_orders_until_payment).
 *
 * Клиент, бросивший страницу ЮKassa, не оставляет после себя ничего: вебхук
 * `payment.canceled` придёт только когда платёж протухнет у ЮKassa (около
 * часа), а всё это время товар списан с остатка и бонусы в резерве. Команда
 * закрывает окно ожидания раньше — по draft_order_ttl_minutes.
 *
 * Перед отменой каждый черновик сверяется со шлюзом (DraftOrderPaymentSync):
 * если холд на самом деле подтверждён, а вебхук не доехал — заказ не
 * отменяется, а финализируется.
 */
final class CancelUnpaidDraftOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-unpaid';

    protected $description = 'Закрыть черновики заказов с истёкшим сроком ожидания оплаты';

    public function handle(DraftOrderPaymentSync $sync): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::Draft)
            ->whereNotNull('awaiting_payment_until')
            ->where('awaiting_payment_until', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($orders->isEmpty()) {
            return self::SUCCESS;
        }

        $finalized = 0;
        $cancelled = 0;

        foreach ($orders as $order) {
            try {
                $result = $sync->sync($order, cancelExpired: true);

                if ($result->status === OrderStatus::Pending) {
                    $finalized++;
                } elseif ($result->status === OrderStatus::Cancelled) {
                    $cancelled++;
                }
            } catch (\Throwable $e) {
                Log::error('Failed to close expired draft order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Обработано черновиков: {$orders->count()}, оформлено: {$finalized}, отменено: {$cancelled}");

        return self::SUCCESS;
    }
}
