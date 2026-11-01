<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Order\AutoCancelPickupOrderAction;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class AutoCancelPickupOrdersCommand extends Command
{
    protected $signature = 'pickup:auto-cancel';

    protected $description = 'Auto-cancel pickup orders with expired timers';

    public function handle(AutoCancelPickupOrderAction $action): int
    {
        $orders = Order::query()
            ->where('shipping_method', ShippingMethod::Pickup)
            ->where('status', OrderStatus::Assembled)
            ->whereNotNull('pickup_timer_expires_at')
            ->where('pickup_timer_expires_at', '<=', now())
            ->limit(100)
            ->get();

        if ($orders->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Found {$orders->count()} expired pickup orders");

        foreach ($orders as $order) {
            try {
                $action->execute($order);
                SendOrderStatusNotification::dispatch($order->id, OrderStatus::Cancelled);
                $this->info("Order #{$order->id} auto-cancelled");
            } catch (\Throwable $e) {
                Log::error('Failed to auto-cancel pickup order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to cancel order #{$order->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
