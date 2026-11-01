<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OrderPickupConfirm
{
    public function __invoke($_, array $args)
    {
        $order = Order::findOrFail($args['id']);

        // При сплите по зонам сборки выдаётся заказ целиком: подтверждение
        // любого подзаказа завершает корневой заказ и все его подзаказы.
        // Та же семантика в delivery_back (выдача из приложения сборщика).
        if ($order->parent_order_id !== null) {
            $order = Order::find($order->parent_order_id) ?? $order;
        }

        if ($order->type_id !== Type::RECEIVE_PICKUP) {
            return ['code' => 422, 'message' => 'Заказ не является самовывозом'];
        }

        if ($order->status_id !== Status::ORDER_ASSEMBLED) {
            return ['code' => 422, 'message' => 'Заказ не в статусе "Собран"'];
        }

        // Сотрудник, подтвердивший выдачу — строго guard api (staff), см. LASTOCHKI-JAMI-9665
        $staff = auth()->guard('api')->user();
        $staffId = $staff?->id;

        $children = collect();

        DB::transaction(function () use ($order, $staffId, &$children) {
            $order->update([
                'status_id' => Status::ORDER_FINISHED,
                'picked_up_at' => now(),
                'picked_up_by_user_id' => $staffId,
                // Момент завершения для статистики выручки: ставим при переводе в 5,
                // но не перезаписываем, если уже проставлен (delivery_back/консьюмер).
                'finished_at' => $order->finished_at ?? now(),
            ]);
            event('eloquent.updated: ' . Order::class, $order);

            // Подзаказы сплита: без этого дети зависали в «Собран» и заказ
            // выглядел незавершённым в выдаче сборщику и в отчётах.
            $children = Order::where('parent_order_id', $order->id)->get();

            if ($children->isNotEmpty()) {
                Order::where('parent_order_id', $order->id)
                    ->update(['status_id' => Status::ORDER_FINISHED]);

                Order::where('parent_order_id', $order->id)
                    ->whereNull('finished_at')
                    ->update(['finished_at' => now()]);
            }
        });

        foreach ($children as $child) {
            OrderHistory::log(
                $child->id,
                OrderHistory::ACTION_DELIVERY_COMPLETED,
                OrderHistory::ACTOR_SYSTEM,
                null,
                null,
                self::statusLabel((int) $child->status_id),
                'Выдан',
                'Синхронизировано с выдачей самовывоза родителя'
            );
        }

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_DELIVERY_COMPLETED,
            OrderHistory::ACTOR_STAFF,
            $staffId,
            $staff ? trim("{$staff->surname} {$staff->name}") : null,
            null,
            'Выдан'
        );

        Log::info('OrderPickupConfirm: order finished', [
            'order_id' => $order->id,
            'picked_up_by_user_id' => $staffId,
            'child_order_ids' => $children->pluck('id')->all(),
        ]);

        return ['code' => 200, 'message' => 'Заказ выдан'];
    }

    private static function statusLabel(int $statusId): string
    {
        return match ($statusId) {
            Status::ORDER_CREATED => 'Создан',
            Status::ORDER_PREPARING => 'Заказ на сборке',
            Status::ORDER_ASSEMBLED => 'Собрано',
            Status::ORDER_IN_DELIVERY => 'В доставке',
            Status::ORDER_FINISHED => 'Доставлен',
            Status::ORDER_CANCELLED => 'Отменён',
            default => "Статус {$statusId}",
        };
    }
}
