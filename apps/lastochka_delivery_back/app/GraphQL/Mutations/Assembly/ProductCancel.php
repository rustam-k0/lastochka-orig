<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\Status;

final class ProductCancel
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $args)
    {
        $assemblyItem = AssemblyItem::with('assembly.order')->find($args['id']);

        if (!$assemblyItem) {
            return [
                'code' => 404,
                'message' => 'Товар не найден'
            ];
        }

        // Причина отмены опциональна (справочник product_cancel_reasons).
        $cancelReasonId = $args['cancel_reason_id'] ?? null;

        // Отменяем товар
        $assemblyItem->update([
            'status_id' => Status::PRODUCT_CANCELED,
            'cancel_reason_id' => $cancelReasonId,
        ]);

        // Честный знак (MID-266): отменённая единица не продаётся — её коды
        // освобождаются (могут быть отсканированы в другом заказе).
        $assemblyItem->markingCodes()->delete();

        // Аналитика «не найдено» (LASTOCHKI-JAMI-11659): источник fill rate
        // и точности остатка
        \App\Services\AnalyticsEvents::itemNotFound($assemblyItem, $cancelReasonId);

        // Дублируем причину на позицию заказа: админка читает order_items
        // из общей БД и показывает причину на странице заказа. Статус позиции
        // переводится в ORDER_ITEM_CANCELED позже — при AssemblyFinish либо в
        // finishAssemblyAndCancelOrder; обе ветки cancel_reason_id не затирают.
        if ($cancelReasonId !== null && $assemblyItem->orderItem) {
            $assemblyItem->orderItem->update([
                'cancel_reason_id' => $cancelReasonId,
            ]);
        }

        // Проверяем, все ли товары в сборке отменены
        $assembly = $assemblyItem->assembly;
        $totalItems = $assembly->assemblyItems()->count();
        $canceledItems = $assembly->assemblyItems()
            ->where('status_id', Status::PRODUCT_CANCELED)
            ->count();

        // Если все товары отменены, завершаем сборку и отменяем заказ
        if ($totalItems === $canceledItems) {
            $oldStatusId = $assembly->order->status_id;

            AssemblyFinish::finishAssemblyAndCancelOrder($assembly);

            // Лог отмены пишется здесь, а не внутри finishAssemblyAndCancelOrder:
            // OrderCancelByCollector логирует отмену сам (с причиной), общий лог
            // внутри статик-метода дал бы дубль записи в OrderHistory.
            $collector = \App\Models\User::find($assembly->user_id);
            \App\Models\OrderHistory::log(
                $assembly->order->id,
                \App\Models\OrderHistory::ACTION_CANCELED,
                \App\Models\OrderHistory::ACTOR_STAFF,
                $assembly->user_id,
                $collector ? trim("{$collector->surname} {$collector->name}") : null,
                self::statusLabel($oldStatusId),
                self::statusLabel(Status::ORDER_CANCELED),
                null
            );

            return [
                'code' => 200,
                'message' => 'Товар отменен. Все товары отменены, сборка завершена, заказ отменен'
            ];
        }

        return [
            'code' => 200,
            'message' => 'Товар отменен'
        ];
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
