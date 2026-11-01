<?php

namespace App\GraphQL\Mutations\Order;

use App\GraphQL\Mutations\Assembly\AssemblyFinish;
use App\Models\Assembly;
use App\Models\Order;
use App\Models\OrderCancelReason;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\User;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OrderCancelByCollector
{
    /**
     * @param null $_
     * @param array{assembly_id: int, reason_id: ?int, custom_reason: ?string} $args
     */
    public function __invoke($_, array $args): array
    {
        $orderId = DB::transaction(function () use ($args) {
            // Assembly::auth() уже гарантирует, что сборка принадлежит текущему
            // сборщику — исключаем отмену чужого заказа.
            $assembly = Assembly::auth()->lockForUpdate()->findOrFail($args['assembly_id']);
            $order    = Order::lockForUpdate()->findOrFail($assembly->order_id);

            if ($order->status_id !== Status::ORDER_PREPARING) {
                throw new UserError('Заказ не находится на сборке');
            }

            $reasonText = $this->resolveReasonText($args);

            // Отменяет ТОЛЬКО этот (суб)заказ — без каскада на соседние
            // подзаказы по другим зонам сборки, симметрично ProductCancel.
            AssemblyFinish::finishAssemblyAndCancelOrder($assembly, $reasonText);

            $collector = User::find($assembly->user_id);
            OrderHistory::log(
                $order->id,
                OrderHistory::ACTION_CANCELED,
                OrderHistory::ACTOR_STAFF,
                $assembly->user_id,
                $collector ? trim("{$collector->surname} {$collector->name}") : null,
                'Заказ на сборке',
                'Отменён',
                $reasonText
            );

            return $order->id;
        });

        // Инициируем возврат денег в purchase_back (он сам проверит тип оплаты).
        $this->notifyPurchaseCancel($orderId);

        return ['code' => 200, 'message' => 'OK'];
    }

    private function resolveReasonText(array $args): ?string
    {
        if (!empty($args['custom_reason'])) {
            return trim($args['custom_reason']);
        }

        if (!empty($args['reason_id'])) {
            return OrderCancelReason::find($args['reason_id'])?->title;
        }

        return null;
    }

    private function notifyPurchaseCancel(int $orderId): void
    {
        $url = config('services.purchase.url') . '/purchase/order/cancel-by-collector';

        try {
            Http::asJson()->post($url, [
                'token'    => config('services.system.token'),
                'order_id' => $orderId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OrderCancelByCollector: failed to notify purchase_back', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
