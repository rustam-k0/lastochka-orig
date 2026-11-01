<?php

namespace App\GraphQL\Mutations\Order;

use App\GraphQL\Mutations\Assembly\ProductConfirm;
use App\Models\DeliveryOrder;
use App\Models\OrderItem;
use App\Models\Status;
use App\Services\CourierReturnService;
use App\Support\DeliveryReturn;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;

final class OrderItemReturnByCourier
{
    /**
     * @param null $_
     * @param array{delivery_order_id:int, order_item_id:int, quantity:?int, reason_id:?int} $args
     */
    public function __invoke($_, array $args): array
    {
        DB::transaction(function () use ($args) {
            $delivery_order = DeliveryOrder::lockForUpdate()->findOrFail($args['delivery_order_id']);

            // Позиция должна принадлежать заказу этого delivery_order и быть собранной.
            $order_item = OrderItem::with(['assemblyItem', 'product'])
                ->where('id', $args['order_item_id'])
                ->where('order_id', $delivery_order->order_id)
                ->lockForUpdate()
                ->first();

            if (!$order_item) {
                throw new UserError('Позиция не найдена в заказе');
            }
            if ($order_item->status_id === Status::ORDER_ITEM_CANCELED) {
                throw new UserError('Позиция отменена и не может быть возвращена');
            }

            $assembly_item = $order_item->assemblyItem;
            $actualWeight = CourierReturnService::effectiveActualWeight($order_item);
            $isWeighted = DeliveryReturn::isWeighted($order_item->product?->isWeightBased(), $actualWeight);

            $stepGrams = $order_item->product
                ? ProductConfirm::getStepGrams($order_item->product)
                : 1;
            $collected = DeliveryReturn::collectedPortions($order_item->quantity, $actualWeight, $stepGrams);
            $alreadyReturned = (int) ($order_item->returned_quantity ?? 0);

            $error = DeliveryReturn::validateReturn($isWeighted, $args['quantity'] ?? null, $alreadyReturned, $collected);
            if ($error !== null) {
                throw new UserError($error);
            }

            $returned = DeliveryReturn::resolveReturnedPortions($isWeighted, $args['quantity'] ?? null, $alreadyReturned, $collected);

            $payload = ['returned_quantity' => $returned];
            if (!empty($args['reason_id'])) {
                $payload['return_reason_id'] = $args['reason_id'];
            }

            $order_item->update($payload);
            $assembly_item?->update($payload);

            // Сумма к оплате должна измениться сразу, а не только при завершении доставки
            if ($delivery_order->order) {
                CourierReturnService::recalcOrderAmount($delivery_order->order);
            }
        });

        return ['code' => 200, 'message' => 'OK'];
    }
}
