<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\DeliveryOrder;
use App\Models\OrderItem;
use App\Services\CourierReturnService;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;

final class OrderItemReturnCancelByCourier
{
    /**
     * @param null $_
     * @param array{delivery_order_id:int, order_item_id:int} $args
     */
    public function __invoke($_, array $args): array
    {
        DB::transaction(function () use ($args) {
            $delivery_order = DeliveryOrder::lockForUpdate()->findOrFail($args['delivery_order_id']);

            $order_item = OrderItem::with('assemblyItem')
                ->where('id', $args['order_item_id'])
                ->where('order_id', $delivery_order->order_id)
                ->lockForUpdate()
                ->first();

            if (!$order_item) {
                throw new UserError('Позиция не найдена в заказе');
            }

            $reset = ['returned_quantity' => 0, 'return_reason_id' => null];
            $order_item->update($reset);
            $order_item->assemblyItem?->update($reset);

            // Возврат снят — сумма к оплате возвращается к полной сразу же
            if ($delivery_order->order) {
                CourierReturnService::recalcOrderAmount($delivery_order->order);
            }
        });

        return ['code' => 200, 'message' => 'OK'];
    }
}
