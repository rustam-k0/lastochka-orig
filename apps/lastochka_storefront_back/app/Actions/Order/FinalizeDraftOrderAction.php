<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\Order\OrderCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\RabbitMQ\Publishers\OrderCreatedPublisher;
use App\Services\Promocode\PromocodeUsageTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Превращает черновик в настоящий заказ после подтверждения онлайн-оплаты
 * (модуль draft_orders_until_payment).
 *
 * Делает ровно то, что при выключенном модуле происходит в момент оформления:
 * очищает корзину, списывает попытку промокода и публикует order.created в
 * платформу. Всё остальное (резерв товара, холд бонусов, снапшоты) уже сделано
 * в CreateOrderAction — черновик отличается от боевого заказа только этими
 * тремя вещами и статусом.
 *
 * Идемпотентен: вызывается из вебхука ЮKassa, из клиентского поллинга статуса
 * и из крона — кто успел первым, тот и финализировал, остальные выходят на
 * проверке статуса под блокировкой строки.
 */
final class FinalizeDraftOrderAction
{
    public function __construct(
        private readonly PromocodeUsageTracker $promocodeUsage,
    ) {}

    public function execute(Order $order): Order
    {
        if ($order->status !== OrderStatus::Draft) {
            return $order;
        }

        [$finalized, $changed] = DB::transaction(function () use ($order) {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return [$order, false];
            }

            // Гонка вебхука, поллинга и крона: черновик уже финализирован
            // (или отменён по TTL) — второй раз ничего не делаем.
            if ($locked->status !== OrderStatus::Draft) {
                return [$locked, false];
            }

            $locked->forceFill([
                'status' => OrderStatus::Pending,
                'awaiting_payment_until' => null,
            ])->save();

            $this->clearCart($locked);
            $this->promocodeUsage->trackFromSnapshot($locked);

            // Событие в платформу — в той же транзакции, что и смена статуса:
            // outbox-запись обязана откатиться вместе с ней (Transactional Outbox).
            OrderCreatedPublisher::publish($locked->fresh(['store']) ?? $locked);

            return [$locked->fresh(), true];
        });

        if (! $changed) {
            return $finalized;
        }

        Log::info('Draft order finalized after payment confirmation', [
            'order_id' => $finalized->id,
            'user_id' => $finalized->user_id,
        ]);

        event(new OrderCreated($finalized));

        return $finalized;
    }

    /**
     * Удаляет из корзины позиции, вошедшие в черновик, и промокод магазина.
     *
     * Именно перечисленные позиции, а не всю корзину магазина: пока клиент был
     * на странице оплаты, он мог добавить товар с другого устройства — этот
     * товар обязан остаться в корзине.
     */
    private function clearCart(Order $order): void
    {
        $cart = Cart::where('user_id', $order->user_id)->first();

        if (! $cart) {
            return;
        }

        $itemIds = $order->draft_cart_item_ids ?? [];

        if ($itemIds !== []) {
            CartItem::where('cart_id', $cart->id)->whereIn('id', $itemIds)->delete();
        }

        $cart->promocodes()->where('store_id', $order->store_id)->delete();
    }
}
