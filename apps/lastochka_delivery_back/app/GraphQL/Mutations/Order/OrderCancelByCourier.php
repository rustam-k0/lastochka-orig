<?php

namespace App\GraphQL\Mutations\Order;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Status;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OrderCancelByCourier
{
    /**
     * @param null $_
     * @param array{delivery_order_id: int, reason: ?string} $args
     */
    public function __invoke($_, array $args): array
    {
        $result = DB::transaction(function () use ($args) {
            // Читаем и блокируем внутри транзакции — исключаем race condition
            $delivery_order = DeliveryOrder::with(['delivery.deliveryOrders'])
                ->lockForUpdate()
                ->findOrFail($args['delivery_order_id']);

            $order    = Order::lockForUpdate()->findOrFail($delivery_order->order_id);
            $delivery = $delivery_order->delivery;

            if ($order->status_id !== Status::ORDER_IN_DELIVERY) {
                throw new UserError('Заказ не находится в доставке');
            }

            // Возврат остатков, списанных при сборке (AssemblyFinish::collectOrderItems).
            // Assembly уже finished_at, поэтому ищем без global scope NotFinished.
            $assembly = Assembly::withoutGlobalScopes()
                ->whereOrderId($order->id)
                ->first();
            if ($assembly) {
                AssemblyFinish::restoreAssembledStock($assembly);
            }

            // Soft-delete delivery_order — исключаем из deliveryOrders() запросов
            $delivery_order->delete();

            // Продвигаем следующий заказ в маршруте (следующий по приоритету)
            $next_order = $delivery->deliveryOrders()
                ->orderBy('priority')
                ->where('priority', '>', $delivery_order->priority)
                ->first();

            $startedCourierIds = [];
            if ($next_order) {
                $next_order->update(['status_id' => Status::DELIVERY_IN_PROGRESS, 'started_at' => now()]);
                $next_order->order->update(['status_id' => Status::ORDER_IN_DELIVERY]);

                // Совместная доставка: следующий заказ маршрута тяжёлый —
                // его соисполнители тоже «В пути» (re-test MID-268)
                $startedCourierIds = \App\Support\JointDelivery::startOtherAssignments($next_order->order, $next_order->id);
            } else {
                // Отменённый заказ был последним в маршруте — завершаем доставку
                $delivery->update(['finished_at' => now()]);
            }

            // Один update: оба поля в одном Eloquent-событии → одна запись в outbox
            $oldStatusId = $order->status_id;
            $updateData = ['status_id' => Status::ORDER_CANCELLED];
            if (!empty($args['reason'])) {
                $updateData['operator_comment'] = $args['reason'];
            }
            $order->update($updateData);

            $courier = \App\Models\User::find($delivery->user_id);
            \App\Models\OrderHistory::log(
                $order->id,
                \App\Models\OrderHistory::ACTION_CANCELED,
                \App\Models\OrderHistory::ACTOR_STAFF,
                $delivery->user_id,
                $courier ? trim("{$courier->surname} {$courier->name}") : null,
                "Статус {$oldStatusId}",
                'Отменён',
                $args['reason'] ?? null
            );

            // Совместная доставка: закрыть назначения остальных курьеров + пересчитать счётчик.
            // Своё назначение уже soft-deleted выше ($delivery_order->delete()) — под null except не попадёт.
            $closedCourierIds = \App\Support\JointDelivery::closeOtherAssignments($order, null, true, $startedCourierIds);
            \App\Support\JointDelivery::recalcCouriersCount($order);

            return [
                'orderId' => $order->id,
                'closedCourierIds' => $closedCourierIds,
                'startedCourierIds' => $startedCourierIds,
                'nextOrderId' => $next_order?->order_id,
            ];
        });

        // Инициируем возврат денег в purchase_back (он сам проверит тип оплаты)
        $this->notifyPurchaseCancel($result['orderId']);

        // Push остальным курьерам — после закрытия транзакции
        foreach ($result['closedCourierIds'] as $courierUserId) {
            app(\App\Services\FcmNotificationService::class)->sendToUser(
                $courierUserId,
                'Заказ закрыт',
                'Заказ ' . Order::labelById((int) $result['orderId']) . ' отменён',
                ['type' => 'order_closed_by_system', 'order_id' => (string) $result['orderId']]
            );
        }

        // Соисполнителям следующего совместного заказа — «Доставка начата»
        if ($result['startedCourierIds'] !== []) {
            \App\Support\JointDelivery::notifyStarted(
                $result['startedCourierIds'],
                (int) ($result['nextOrderId'] ?? $result['orderId'])
            );
        }

        return ['code' => 200, 'message' => 'OK'];
    }

    private function notifyPurchaseCancel(int $orderId): void
    {
        $url = config('services.purchase.url') . '/purchase/order/cancel-by-courier';

        try {
            Http::asJson()->post($url, [
                'token'    => config('services.system.token'),
                'order_id' => $orderId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OrderCancelByCourier: failed to notify purchase_back', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
