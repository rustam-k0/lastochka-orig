<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Events\Order\OrderCancelled;
use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\User;
use App\RabbitMQ\Debug\LoggingEventPublisher;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AutoCancelPickupOrderAction
{
    public function __construct(
        private ReleaseProductsAction $releaseProducts,
        private PaymentService $paymentService,
    ) {}

    public function execute(Order $order): Order
    {
        // Validate this is a pickup order
        if ($order->shipping_method !== ShippingMethod::Pickup) {
            throw new \InvalidArgumentException('AutoCancelPickupOrderAction: not a pickup order');
        }

        // Skip if not in assembled status (idempotent)
        if ($order->status !== OrderStatus::Assembled) {
            Log::info('AutoCancelPickupOrderAction: order not assembled, skipping', [
                'order_id' => $order->id,
                'status' => $order->status->value,
            ]);

            return $order;
        }

        return DB::transaction(function () use ($order) {
            // Lock to prevent race condition with manual pickup confirm
            $order = Order::lockForUpdate()->findOrFail($order->id);

            // Re-check status after lock
            if ($order->status !== OrderStatus::Assembled) {
                return $order;
            }

            $order->load('store');

            // Return products to stock
            $this->releaseProducts->execute($order);

            // Refund spent bonuses
            if ($order->bonus_spent > 0) {
                $user = User::lockForUpdate()->findOrFail($order->user_id);
                $user->bonus_balance += $order->bonus_spent;
                $user->save();

                BonusTransaction::create([
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'type' => 'refund',
                    'amount' => $order->bonus_spent,
                    'balance_after' => $user->bonus_balance,
                    'description' => "Возврат за автоотмену pickup-заказа #{$order->id}",
                    'created_at' => now(),
                ]);
            }

            // Update status and clear timer
            $order->update([
                'status' => OrderStatus::Cancelled,
                'pickup_timer_expires_at' => null,
            ]);

            // Refund captured payment / release hold (capture happens at assembled for pickup)
            if ($order->payment_type === PaymentType::Card) {
                $this->paymentService->refundOrReleaseHold($order);
            }

            // Fire event
            event(new OrderCancelled($order));

            // Publish to RabbitMQ
            LoggingEventPublisher::dispatch(
                entityType: 'orders',
                model: $order,
                eventType: 'order_cancelled',
                payload: ['cancel_reason' => 'pickup_timer_expired'],
                shopCode: $order->shopCodeForEvents()
            );

            Log::info('Pickup order auto-cancelled', ['order_id' => $order->id]);

            return $order->fresh();
        });
    }
}
