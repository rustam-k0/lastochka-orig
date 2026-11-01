<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Order\CancelOrderAction;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Автоотмена заказов, ожидающих доплату дольше surcharge_wait_hours
 * (модуль collector_payment). Заказ остаётся в Assembling (сборка не
 * завершена из-за неуспешной доплаты) — Assembling входит в
 * OrderStatus::cancellable(), поэтому используется штатный CancelOrderAction:
 * возврат стока и бонусов, публикация order_cancelled (докер-модули отменят
 * сборку), снятие холда.
 */
final class CancelExpiredSurchargeOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-expired-surcharge';

    protected $description = 'Отменить заказы с истёкшим сроком ожидания доплаты';

    public function handle(CancelOrderAction $action): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::Assembling)
            ->where('payment_type', PaymentType::Card)
            ->whereNotNull('surcharge_expires_at')
            ->where('surcharge_expires_at', '<=', now())
            ->limit(100)
            ->get();

        if ($orders->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($orders as $order) {
            try {
                $action->execute($order);
                SendOrderStatusNotification::dispatch($order->id, OrderStatus::Cancelled);

                Log::info('Order cancelled: surcharge wait expired', [
                    'order_id' => $order->id,
                    'surcharge_amount' => $order->surcharge_amount,
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to cancel expired-surcharge order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
