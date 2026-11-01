<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Аутентифицированный сотрудник/админ курьерской системы (docker-БД).
 *
 * employee_id в журнале analytics_events = users.id docker-БД,
 * store_id = shops.code — контекст несёт оба измерения.
 */
final class StaffContext
{
    /**
     * @param  list<string>  $roles  Имена ролей spatie (collector, courier, admin, superadmin, ...)
     * @param  list<string>  $shopCodes  Коды магазинов юзера (user_shops, живые привязки)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $roles,
        public readonly array $shopCodes,
    ) {}

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['admin', 'superadmin']);
    }

    public function isPicker(): bool
    {
        return $this->hasAnyRole(['collector']);
    }

    public function isCourier(): bool
    {
        return $this->hasAnyRole(['courier']);
    }

    /**
     * @param  list<string>  $roles
     */
    private function hasAnyRole(array $roles): bool
    {
        return array_intersect($roles, $this->roles) !== [];
    }
}
