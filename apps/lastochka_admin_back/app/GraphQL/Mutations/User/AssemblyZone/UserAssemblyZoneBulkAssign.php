<?php

namespace App\GraphQL\Mutations\User\AssemblyZone;

use App\Models\UserAssemblyZone;
use Illuminate\Support\Facades\DB;

final class UserAssemblyZoneBulkAssign
{
    /**
     * Массовая привязка сотрудников к одной зоне в магазине.
     *
     * assembly_zone_id = null → снять привязки у всех перечисленных сотрудников.
     * date = null → постоянная; date = дата → на конкретный день.
     */
    public function __invoke($_, array $args): array
    {
        $userIds = array_map('intval', $args['user_ids']);
        $shopId  = (int) $args['shop_id'];
        $date    = $args['date'] ?? null;
        $zoneId  = $args['assembly_zone_id'] ?? null;

        DB::transaction(function () use ($userIds, $shopId, $date, $zoneId) {
            foreach ($userIds as $userId) {
                $query = UserAssemblyZone::where('user_id', $userId)
                    ->where('shop_id', $shopId);

                $date === null
                    ? $query->whereNull('date')
                    : $query->whereDate('date', $date);

                if ($zoneId === null) {
                    $query->delete();
                    continue;
                }

                UserAssemblyZone::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'shop_id' => $shopId,
                        'date'    => $date,
                    ],
                    [
                        'assembly_zone_id' => (int) $zoneId,
                    ],
                );
            }
        });

        return [
            'code'    => 200,
            'message' => 'OK',
        ];
    }
}
