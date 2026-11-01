<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Ограничения для партнёра-селлера (shop-scope) при управлении сотрудниками.
 *
 * Супер-админ платформы управляет всеми сотрудниками без ограничений.
 * Партнёр может заводить/редактировать/удалять только сборщиков и курьеров
 * и только в своих магазинах (привязка автоматическая, из user_shops).
 */
class PartnerStaffAccess
{
    /** Роли, которые партнёр вправе назначать сотрудникам. */
    public const ALLOWED_ROLES = [Role::COLLECTOR, Role::COURIER];

    /**
     * Является ли пользователь партнёром (shop-scope, не глобальный админ).
     */
    public static function isPartner(?User $user): bool
    {
        return $user !== null && !$user->isGlobalScope() && $user->isShopScope();
    }

    /**
     * Допустима ли роль (по системному имени) для назначения партнёром.
     */
    public static function roleAllowed(?string $roleName): bool
    {
        return in_array($roleName, self::ALLOWED_ROLES, true);
    }

    /**
     * Активные shop_id магазинов партнёра.
     *
     * @return int[]
     */
    public static function ownShopIds(User $user): array
    {
        return $user->shops()->pluck('shop_id')->map(fn ($id) => (int) $id)->all();
    }
}
