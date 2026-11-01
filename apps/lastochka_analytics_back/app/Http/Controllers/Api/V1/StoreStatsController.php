<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Stats\StoreStatsService;
use App\Support\StaffContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Сводка магазина для B2B-админки (#11603):
 * GET /api/v1/analytics/employees и /store/summary. Только admin/superadmin.
 */
final class StoreStatsController extends Controller
{
    public function employees(Request $request, StoreStatsService $stats): JsonResponse
    {
        $staff = $this->adminOr403($request);

        if ($staff instanceof JsonResponse) {
            return $staff;
        }

        $validation = $this->validateParams($request);

        if ($validation !== null) {
            return $validation;
        }

        return response()->json([
            'success' => true,
            'data' => $stats->employees(
                $staff,
                $request->query('store_id'),
                $request->query('role'),
                (string) $request->query('period', 'week'),
            ),
        ]);
    }

    public function summary(Request $request, StoreStatsService $stats): JsonResponse
    {
        $staff = $this->adminOr403($request);

        if ($staff instanceof JsonResponse) {
            return $staff;
        }

        $validation = $this->validateParams($request);

        if ($validation !== null) {
            return $validation;
        }

        return response()->json([
            'success' => true,
            'data' => $stats->summary(
                $staff,
                $request->query('store_id'),
                (string) $request->query('period', 'week'),
            ),
        ]);
    }

    private function adminOr403(Request $request): StaffContext|JsonResponse
    {
        /** @var StaffContext $staff */
        $staff = $request->attributes->get('staff');

        if (! $staff->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Доступно только администратору',
            ], 403);
        }

        return $staff;
    }

    private function validateParams(Request $request): ?JsonResponse
    {
        $period = $request->query('period');

        if ($period !== null && ! in_array($period, ['day', 'week', 'month'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'period должен быть одним из: day, week, month',
            ], 422);
        }

        $role = $request->query('role');

        if ($role !== null && ! in_array($role, ['picker', 'courier'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'role должен быть одним из: picker, courier',
            ], 422);
        }

        return null;
    }
}
