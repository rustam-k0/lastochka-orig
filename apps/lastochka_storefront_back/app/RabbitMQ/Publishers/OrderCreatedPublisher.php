<?php

declare(strict_types=1);

namespace App\RabbitMQ\Publishers;

use App\Models\Order;
use Comrades\RabbitMQEvents\Observers\ModelEventObserver;
use Illuminate\Support\Facades\Log;

/**
 * Отложенный выпуск события `order.created` для заказа, который родился
 * черновиком (модуль draft_orders_until_payment).
 *
 * Обычные заказы публикует Eloquent-observer пакета на Order::create().
 * Черновик через него не проходит — OrderNotDraftCondition отсекает событие
 * до записи в outbox. После подтверждения оплаты заказ обязан уехать в
 * платформу ровно тем же событием: курьерка слушает `order.created` и НЕ
 * слушает кастомное `order.order_created` (см. routing_key_patterns в
 * admin_back), поэтому CustomEventPublisher здесь не подходит.
 *
 * Поэтому переиспользуем сам observer пакета: он соберёт payload
 * трансформером, заведёт EventSyncMetadata, соберёт routing key и запишет
 * в outbox — один в один как при обычном создании заказа.
 */
final class OrderCreatedPublisher
{
    /**
     * @return bool true — событие ушло в outbox
     */
    public static function publish(Order $order): bool
    {
        $entityConfig = config('rabbitmq-events.entities.orders');

        if (! is_array($entityConfig) || ! ($entityConfig['enabled'] ?? false)) {
            Log::warning('OrderCreatedPublisher: orders entity disabled, event skipped', [
                'order_id' => $order->id,
            ]);

            return false;
        }

        (new ModelEventObserver('orders', $entityConfig))->created($order);

        return true;
    }
}
