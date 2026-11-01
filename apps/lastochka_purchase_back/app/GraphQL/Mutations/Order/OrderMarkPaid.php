<?php

namespace App\GraphQL\Mutations\Order;

use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ручная отметка оплаты наличного заказа сотрудником из карточки заказа.
 *
 * Онлайн-заказы (карта) оплачиваются через storefront/YooKassa и приходят
 * событием order_paid, для них ручная отметка запрещена. Для наличных
 * платформа сама факт оплаты не фиксирует (чек по наличным storefront
 * пробивает на «Доставлен» независимо от этого флага), поэтому отметку
 * ставит сотрудник, принявший деньги.
 */
final class OrderMarkPaid
{
    public function __invoke($_, array $args)
    {
        $order = Order::findOrFail($args['id']);

        if ($order->parent_order_id !== null) {
            return ['code' => 422, 'message' => 'Отметьте оплату на основном заказе, подзаказы оплачиваются вместе с ним'];
        }

        if ((int) $order->payment_type_id !== Type::PAYMENT_CASH) {
            return ['code' => 422, 'message' => 'Отметить оплату вручную можно только для заказа с оплатой наличными'];
        }

        if ($order->status_id === Status::ORDER_CANCELLED) {
            return ['code' => 422, 'message' => 'Отменённый заказ нельзя отметить оплаченным'];
        }

        if ($order->paid) {
            return ['code' => 200, 'message' => 'Заказ уже отмечен оплаченным'];
        }

        // Сотрудник, зафиксировавший оплату — строго guard api (staff)
        $staff = auth()->guard('api')->user();
        $staffId = $staff?->id;
        $staffName = $staff ? trim("{$staff->surname} {$staff->name}") : null;

        $oldPaymentStatus = $order->payment_status;
        $paidAt = now();

        DB::transaction(function () use ($order, $paidAt) {
            // Родитель — через Eloquent: outbox публикует order.updated с is_paid=true.
            // Подзаказы — mass-update: их события заблокированы OrderNotSubOrderCondition,
            // но флаг нужен локально (сборщик/экспорт видят подзаказ) — зеркально
            // AssemblyChargePayment::markPaid в delivery_back.
            $order->update([
                'paid' => true,
                'paid_at' => $paidAt,
                'payment_status' => 'paid',
            ]);

            Order::where('parent_order_id', $order->id)->update([
                'paid' => true,
                'paid_at' => $paidAt,
                'payment_status' => 'paid',
            ]);
        });

        OrderHistory::log(
            $order->id,
            OrderHistory::ACTION_PAYMENT_FIXED,
            OrderHistory::ACTOR_STAFF,
            $staffId,
            $staffName,
            self::paymentStatusLabel($oldPaymentStatus),
            self::paymentStatusLabel('paid'),
            'Оплата наличными отмечена вручную'
        );

        Log::info('OrderMarkPaid: cash order marked as paid', [
            'order_id' => $order->id,
            'staff_id' => $staffId,
        ]);

        return ['code' => 200, 'message' => 'Заказ отмечен оплаченным'];
    }

    /**
     * Подписи статусов оплаты для истории — 1:1 с admin_back
     * OrderEventTransformer::paymentStatusLabel, чтобы записи ручной и
     * автоматической фиксации оплаты читались одинаково.
     */
    private static function paymentStatusLabel(?string $status): string
    {
        return match ($status) {
            'paid' => 'Оплачен',
            'failed' => 'Ошибка оплаты',
            'refunded' => 'Возврат',
            'hold' => 'Заблокировано (hold)',
            'awaiting_surcharge' => 'Ожидает доплату',
            default => 'Не оплачен',
        };
    }
}
