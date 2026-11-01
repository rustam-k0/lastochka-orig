<?php

namespace App\GraphQL\Mutations\User\AssemblyZone;

use App\Models\UserAssemblyZone;

final class UserAssemblyZoneAssign
{
    /**
     * Назначить/обновить/снять зону сборки сотруднику в магазине.
     *
     * assembly_zone_id = null → снять привязку (для соответствующей даты).
     * date = null → постоянная привязка; date = дата → на конкретный день.
     */
    public function __invoke($_, array $args): array
    {
        $userId = (int) $args['user_id'];
        $shopId = (int) $args['shop_id'];
        $date   = $args['date'] ?? null;
        $zoneId = $args['assembly_zone_id'] ?? null;

        $query = UserAssemblyZone::where('user_id', $userId)
            ->where('shop_id', $shopId);

        $date === null
            ? $query->whereNull('date')
            : $query->whereDate('date', $date);

        if ($zoneId === null) {
            $query->delete();

            return [
                'code'    => 200,
                'message' => 'Привязка снята',
            ];
        }

        UserAssemblyZone::updateOrCreate(
            [
                'user_id'  => $userId,
                'shop_id'  => $shopId,
                'date'     => $date,
            ],
            [
                'assembly_zone_id' => (int) $zoneId,
            ],
        );

        return [
            'code'    => 200,
            'message' => 'OK',
        ];
    }
}
