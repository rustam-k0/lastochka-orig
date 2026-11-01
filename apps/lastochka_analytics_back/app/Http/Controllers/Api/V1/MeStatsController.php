<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Stats\AchievementsService;
use App\Services\Stats\EmployeeStatsService;
use App\Support\StaffContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Личная статистика сотрудника (#11597/#11602):
 * GET /api/v1/analytics/me и /me/achievements.
 */
final class MeStatsController extends Controller
{
    public function me(Request $request, EmployeeStatsService $stats): JsonResponse
    {
        $period = (string) $request->query('period', 'shift');

        if (! in_array($period, ['shift', 'week', 'month'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'period должен быть одним из: shift, week, month',
            ], 422);
        }

        $role = $request->query('role');

        if ($role !== null && ! in_array($role, ['picker', 'courier'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'role должен быть одним из: picker, courier',
            ], 422);
        }

        /** @var StaffContext $staff */
        $staff = $request->attributes->get('staff');

        return response()->json([
            'success' => true,
            'data' => $stats->me($staff, $period, $role),
        ]);
    }

    public function achievements(Request $request, AchievementsService $achievements): JsonResponse
    {
        $role = $request->query('role');

        if ($role !== null && ! in_array($role, ['picker', 'courier'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'role должен быть одним из: picker, courier',
            ], 422);
        }

        /** @var StaffContext $staff */
        $staff = $request->attributes->get('staff');

        return response()->json([
            'success' => true,
            'data' => $achievements->forStaff($staff, $role),
        ]);
    }
}
