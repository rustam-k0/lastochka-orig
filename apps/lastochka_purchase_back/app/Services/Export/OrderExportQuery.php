<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Построение выборки заказов для Excel-выгрузки.
 * Скоупинг по магазину и отсечение подзаказов даёт Order::scopeAdmin —
 * тот же, что применяется в admin_orders_paginate.
 */
class OrderExportQuery
{
    private const LIMIT = 10000;

    public function build(array $input): Builder
    {
        $query = Order::query()->with([
            'orderItems.product' => static fn ($q) => $q->withTrashed(),
            'shop',
            'client',
            'clientInfo',
        ]);

        $this->applyAdminScope($query);

        if (array_key_exists('order_ids', $input)) {
            // Выгрузка выделенных строк: фильтры экрана намеренно не применяются
            $query->whereIn('id', $input['order_ids']);
        } else {
            $query->filter($this->filterArgs($input));
        }

        return $query->orderBy('created_at', 'desc')->limit(self::LIMIT);
    }

    /**
     * Скоупинг по магазину и отсечение подзаказов через Order::scopeAdmin(),
     * но без его фатала: если у не-глобального пользователя нет привязанного
     * магазина (`user_shops` пуст), scopeAdmin() обратится к shop_id на null.
     * Историческое поведение экспорта в этом случае — пустая выборка
     * (было whereRaw('1 = 0') прямо в контроллере), а не 500 — сохраняем его
     * здесь, не трогая сам scopeAdmin(), которым пользуется admin_orders_paginate.
     */
    private function applyAdminScope(Builder $query): void
    {
        $user = auth()->guard('api')->user();

        if (!$user->seesAllShopsOrders() && !$user->shops()->first()) {
            $query->whereNull('parent_order_id')->whereRaw('1 = 0');
            return;
        }

        $query->admin();
    }

    /**
     * Аргументы для OrderFilter — тот же набор, что у admin_orders_paginate,
     * чтобы выгрузка совпадала с тем, что видно на экране.
     * QueryFilter::apply() молча игнорирует ключи без соответствующего метода
     * и пустые значения, поэтому передавать весь набор безопасно.
     */
    private function filterArgs(array $input): array
    {
        $args = array_intersect_key($input, array_flip([
            'title',
            'shops',
            'statuses',
            'types',
            'payment_types',
            'payment_statuses',
            'couriers',
            'collectors',
            'client_search',
            'amount_from',
            'amount_to',
            'created_at_start_date',
            'created_at_end_date',
        ]));

        return array_filter($args, static fn ($value) => $value !== null && $value !== '');
    }
}
