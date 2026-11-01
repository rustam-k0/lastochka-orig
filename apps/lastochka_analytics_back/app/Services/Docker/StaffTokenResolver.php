<?php

declare(strict_types=1);

namespace App\Services\Docker;

use App\Support\StaffContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Валидация Bearer Sanctum-токена по docker-БД без пакета Sanctum:
 * токен «id|plain» → sha256(plain) сверяется с personal_access_tokens,
 * затем грузятся юзер, роли (spatie) и магазины.
 *
 * Модель юзера в docker-БД одна на сборщиков/курьеров/админов —
 * токены delivery_back и admin_back валидируются одинаково.
 */
final class StaffTokenResolver
{
    private const USER_MODEL = 'App\\Models\\User';

    public function resolve(?string $bearer): ?StaffContext
    {
        $token = $this->findToken($bearer);

        if ($token === null) {
            return null;
        }

        // В docker-БД колонки expires_at может не быть (старый Sanctum) —
        // отсутствие колонки означает бессрочный токен.
        $expiresAt = $token->expires_at ?? null;

        if ($expiresAt !== null && CarbonImmutable::parse($expiresAt)->isPast()) {
            return null;
        }

        $user = DB::connection('docker')->table('users')
            ->where('id', $token->tokenable_id)
            ->whereNull('deleted_at')
            ->first();

        if ($user === null) {
            return null;
        }

        return new StaffContext(
            id: (int) $user->id,
            name: trim(($user->name ?? '').' '.($user->surname ?? '')),
            roles: $this->roles((int) $user->id),
            shopCodes: $this->shopCodes((int) $user->id),
        );
    }

    private function findToken(?string $bearer): ?object
    {
        if ($bearer === null || trim($bearer) === '') {
            return null;
        }

        $query = DB::connection('docker')->table('personal_access_tokens');

        if (str_contains($bearer, '|')) {
            [$id, $plain] = explode('|', $bearer, 2);

            if (! ctype_digit($id)) {
                return null;
            }

            $token = $query->where('id', (int) $id)->first();

            return ($token !== null && hash_equals($token->token, hash('sha256', $plain)))
                ? $token
                : null;
        }

        // Токен без префикса id — легаси-формат Sanctum
        return $query->where('token', hash('sha256', $bearer))->first();
    }

    /**
     * @return list<string>
     */
    private function roles(int $userId): array
    {
        return DB::connection('docker')->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::USER_MODEL)
            ->where('model_has_roles.model_id', $userId)
            ->pluck('roles.name')
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function shopCodes(int $userId): array
    {
        return DB::connection('docker')->table('user_shops')
            ->join('shops', 'shops.id', '=', 'user_shops.shop_id')
            ->where('user_shops.user_id', $userId)
            ->whereNull('user_shops.deleted_at')
            ->whereNotNull('shops.code')
            ->pluck('shops.code')
            ->map(fn ($code) => (string) $code)
            ->unique()
            ->values()
            ->all();
    }
}
