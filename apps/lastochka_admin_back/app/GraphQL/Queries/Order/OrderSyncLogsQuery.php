<?php

namespace App\GraphQL\Queries\Order;

use App\Models\OrderSyncLog;
use Illuminate\Support\Collection;

class OrderSyncLogsQuery
{
    /**
     * Журнал синхронизации заказа с 1С.
     * $args['order_number'] — номер заказа (= orders.uuid, id сторфронта;
     * у подзаказов суффикс «-N», который отрезаем).
     *
     * @return Collection<int, OrderSyncLog>
     */
    public function __invoke($_, array $args): Collection
    {
        // БД сервиса 1c_parser может быть не сконфигурирована на форке/окружении
        // (DB_1C_DATABASE не задан) — тогда журнал недоступен, отдаём пустой список,
        // а не роняем карточку заказа 500-й ошибкой «table doesn't exist».
        if (empty(config('database.connections.one_c.database'))) {
            return collect();
        }

        $number = (string) $args['order_number'];
        $shopOrderId = (int) preg_replace('/-\d+$/', '', $number);

        if ($shopOrderId <= 0) {
            return collect();
        }

        return OrderSyncLog::where('shop_order_id', $shopOrderId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
