<?php

namespace App\Support;

/**
 * Реестр прав на действия с заказом (action-level матрицы доступов,
 * MEN-IN-DEV-11284). Ключи = имена Spatie-permissions (guard api) и значения
 * order_action_keys в GraphQL. Enforcement: purchase_back (@orderAction,
 * OrderCanBeCanceled, OrderExportController), delivery_back (AdminPolicy),
 * admin_front (canOrderAction).
 */
class OrderAction
{
    public const CANCEL = 'orders.cancel';
    public const CHANGE_STATUS = 'orders.change_status';
    public const ASSIGN_COLLECTOR = 'orders.assign_collector';
    public const ASSIGN_COURIER = 'orders.assign_courier';
    public const CHANGE_SHOP = 'orders.change_shop';
    public const EXPORT = 'orders.export';
    public const MARK_PAID = 'orders.mark_paid';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::CANCEL,
            self::CHANGE_STATUS,
            self::ASSIGN_COLLECTOR,
            self::ASSIGN_COURIER,
            self::CHANGE_SHOP,
            self::EXPORT,
            self::MARK_PAID,
        ];
    }
}
