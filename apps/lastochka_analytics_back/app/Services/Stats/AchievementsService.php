<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Support\StaffContext;
use Illuminate\Support\Facades\DB;

/**
 * Бейджи, цели и личные рекорды для GET /api/v1/analytics/me/achievements
 * (#11602). Пороги — config('analytics.badges'), цели — config('analytics.goals').
 */
final class AchievementsService
{
    public function __construct(private readonly EmployeeStatsService $stats) {}

    /**
     * @return array<string, mixed>
     */
    public function forStaff(StaffContext $staff, ?string $roleParam): array
    {
        $role = $this->stats->resolveRole($staff, $roleParam);
        $live = $this->stats->liveShift($staff, $role);

        $bestDay = DB::table('employee_daily_stats')
            ->where('employee_id', (string) $staff->id)
            ->where('role', $role)
            ->orderByDesc('orders_count')
            ->orderByDesc('stat_date')
            ->first();

        $goalTarget = (int) config('analytics.goals.shift', 10);
        $liveOrders = (int) $live['orders_count'];

        return [
            'role' => $role,
            'goal' => [
                'period' => 'shift',
                'target' => $goalTarget,
                'current' => $liveOrders,
                'progress' => $goalTarget > 0 ? round(min(1, $liveOrders / $goalTarget), 2) : null,
            ],
            'records' => [
                'best_day_orders' => (int) ($bestDay->orders_count ?? 0),
                'best_day_date' => $bestDay->stat_date ?? null,
            ],
            'badges' => $this->badges($staff, $role, $live),
            'vs_team_percentile' => $live['vs_team_percentile'],
        ];
    }

    /**
     * @param  array<string, mixed>  $live  Живая статистика смены (EmployeeStatsService::liveShift)
     * @return list<array<string, mixed>>
     */
    private function badges(StaffContext $staff, string $role, array $live): array
    {
        $badges = [$this->sprinter($role, $live)];

        if ($role === 'picker') {
            $badges[] = $this->noErrorsStreak($staff->id);
            $badges[] = $this->fullQrShift($live);
        } else {
            $badges[] = $this->fastCourier($live);
            $badges[] = $this->fiveStarStreak($staff->id);
        }

        $badges[] = $this->topOfDay($staff->id, $role);
        $badges[] = $this->noComplaintDays($staff->id, $role);

        return $badges;
    }

    /**
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function sprinter(string $role, array $live): array
    {
        $target = (int) config('analytics.badges.sprinter_orders_per_shift', 7);
        $current = (int) $live['orders_count'];

        return [
            'code' => 'sprinter',
            'title' => $role === 'picker' ? 'Спринтер' : 'Быстрый старт',
            'description' => "{$target} заказов за смену",
            'earned' => $current >= $target,
            'progress' => $target > 0 ? round(min(1, $current / $target), 2) : null,
            'params' => ['target' => $target, 'current' => $current],
        ];
    }

    /**
     * Последние N собранных заказов подряд без замен и отмен.
     *
     * @return array<string, mixed>
     */
    private function noErrorsStreak(int $employeeId): array
    {
        $target = (int) config('analytics.badges.no_errors_streak_orders', 10);

        $recent = DB::table('analytics_events')
            ->where('event_type', 'picking.finished')
            ->where('employee_id', (string) $employeeId)
            ->orderByDesc('occurred_at')
            ->limit($target)
            ->get();

        $streak = 0;

        foreach ($recent as $event) {
            $payload = json_decode((string) $event->payload, true) ?: [];

            if ((int) ($payload['replaced_items'] ?? 0) === 0 && (int) ($payload['cancelled_items'] ?? 0) === 0) {
                $streak++;
            } else {
                break;
            }
        }

        return [
            'code' => 'no_errors_streak',
            'title' => 'Без ошибок',
            'description' => "{$target} заказов подряд без замен и отмен",
            'earned' => $streak >= $target,
            'progress' => $target > 0 ? round(min(1, $streak / $target), 2) : null,
            'params' => ['target' => $target, 'current' => $streak],
        ];
    }

    /**
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function fullQrShift(array $live): array
    {
        $qr = (int) $live['scan_qr_count'];
        $manual = (int) $live['scan_manual_count'];

        return [
            'code' => 'full_qr_shift',
            'title' => '100% QR',
            'description' => 'Все сканы смены через QR',
            'earned' => $qr > 0 && $manual === 0,
            'progress' => ($qr + $manual) > 0 ? round($qr / ($qr + $manual), 2) : 0.0,
            'params' => ['scan_qr_count' => $qr, 'scan_manual_count' => $manual],
        ];
    }

    /**
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function fastCourier(array $live): array
    {
        $target = (int) config('analytics.badges.fast_courier_avg_sec', 1500);
        $avg = $live['avg_handle_sec'];

        return [
            'code' => 'fast_courier',
            'title' => 'Быстрый',
            'description' => 'Средняя доставка быстрее '.(int) round($target / 60).' минут',
            'earned' => $avg !== null && (int) $live['orders_count'] > 0 && $avg < $target,
            'progress' => $avg !== null && $avg > 0 ? round(min(1, $target / $avg), 2) : 0.0,
            'params' => ['target_sec' => $target, 'avg_handle_sec' => $avg],
        ];
    }

    /**
     * Последние N оценок доставленных заказов курьера — все «5».
     *
     * @return array<string, mixed>
     */
    private function fiveStarStreak(int $employeeId): array
    {
        $target = (int) config('analytics.badges.five_star_streak_ratings', 8);

        $orderIds = DB::table('analytics_events')
            ->where('event_type', 'delivery.finished')
            ->where('employee_id', (string) $employeeId)
            ->whereNotNull('order_id')
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->pluck('order_id')
            ->unique()
            ->values();

        $streak = 0;

        if ($orderIds->isNotEmpty()) {
            $ratings = DB::table('analytics_events')
                ->whereIn('event_type', ['rating.received', 'rating.updated'])
                ->whereIn('order_id', $orderIds)
                ->orderByDesc('occurred_at')
                ->get()
                ->unique('order_id'); // первая в desc-порядке = актуальная оценка заказа

            foreach ($ratings->take($target) as $event) {
                $payload = json_decode((string) $event->payload, true) ?: [];

                if ((int) ($payload['rating'] ?? 0) === 5) {
                    $streak++;
                } else {
                    break;
                }
            }
        }

        return [
            'code' => 'five_star_streak',
            'title' => '5 звёзд',
            'description' => "{$target} оценок «5» подряд",
            'earned' => $streak >= $target,
            'progress' => $target > 0 ? round(min(1, $streak / $target), 2) : null,
            'params' => ['target' => $target, 'current' => $streak],
        ];
    }

    /**
     * Первое место по заказам за сегодня среди своей роли и магазина.
     *
     * @return array<string, mixed>
     */
    private function topOfDay(int $employeeId, string $role): array
    {
        $today = $this->stats->today()->toDateString();

        $rows = DB::table('employee_daily_stats')
            ->where('role', $role)
            ->where('stat_date', $today)
            ->pluck('orders_count', 'employee_id');

        $mine = (int) ($rows[(string) $employeeId] ?? 0);
        $max = (int) ($rows->max() ?? 0);

        return [
            'code' => 'top_of_day',
            'title' => 'Топ смены',
            'description' => 'Первое место по заказам за день',
            'earned' => $mine > 0 && $mine >= $max,
            'progress' => $max > 0 ? round(min(1, $mine / $max), 2) : 0.0,
            'params' => ['current' => $mine, 'leader' => $max],
        ];
    }

    /**
     * Серия рабочих дней (orders_count > 0) подряд без жалоб.
     *
     * @return array<string, mixed>
     */
    private function noComplaintDays(int $employeeId, string $role): array
    {
        $target = (int) config('analytics.badges.no_complaint_days', 5);

        $days = DB::table('employee_daily_stats')
            ->where('employee_id', (string) $employeeId)
            ->where('role', $role)
            ->where('orders_count', '>', 0)
            ->orderByDesc('stat_date')
            ->limit($target)
            ->pluck('complaint_count');

        $streak = 0;

        foreach ($days as $complaints) {
            if ((int) $complaints === 0) {
                $streak++;
            } else {
                break;
            }
        }

        return [
            'code' => 'no_complaint_days',
            'title' => 'Серия без жалоб',
            'description' => "{$target} рабочих дней подряд без жалоб",
            'earned' => $streak >= $target,
            'progress' => $target > 0 ? round(min(1, $streak / $target), 2) : null,
            'params' => ['target' => $target, 'current' => $streak],
        ];
    }
}
