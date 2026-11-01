<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Support\StaffContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Сводка магазина для B2B-админки (#11603):
 * GET /api/v1/analytics/employees (лидерборд) и /store/summary.
 *
 * Доступ: роль admin/superadmin. Если у админа есть привязки user_shops —
 * он видит только свои магазины; без привязок — все.
 */
final class StoreStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function employees(StaffContext $staff, ?string $storeId, ?string $role, string $period): array
    {
        $stores = $this->allowedStores($staff, $storeId);
        $from = $this->periodStart($period);

        $rows = DB::table('employee_daily_stats')
            ->selectRaw(<<<'SQL'
                employee_id,
                role,
                MAX(store_id) AS store_id,
                SUM(orders_count) AS orders_count,
                SUM(items_count) AS items_count,
                SUM(gmv_amount) AS gmv_amount,
                SUM(COALESCE(avg_handle_sec, 0) * orders_count) AS handle_weighted,
                SUM(on_time_count) AS on_time_count,
                SUM(sla_total) AS sla_total,
                SUM(replace_count) AS replace_count,
                SUM(remove_count) AS remove_count,
                SUM(rating_sum) AS rating_sum,
                SUM(rating_count) AS rating_count,
                SUM(complaint_count) AS complaint_count,
                SUM(distance_m) AS distance_m,
                SUM(shift_seconds) AS shift_seconds
            SQL)
            ->where('stat_date', '>=', $from->toDateString())
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->when($stores !== null, fn ($query) => $query->whereIn('store_id', $stores))
            ->groupBy('employee_id', 'role')
            ->orderByDesc(DB::raw('SUM(orders_count)'))
            ->get();

        $names = $this->employeeNames($rows->pluck('employee_id')->unique()->values()->all());

        $leaderboard = $rows->map(function ($row) use ($names) {
            $orders = (int) $row->orders_count;
            $items = (int) $row->items_count;

            return [
                'employee_id' => (string) $row->employee_id,
                'name' => $names[(string) $row->employee_id] ?? null,
                'role' => (string) $row->role,
                'store_id' => $row->store_id,
                'orders_count' => $orders,
                'items_count' => $items,
                'gmv_amount' => round((float) $row->gmv_amount, 2),
                'avg_handle_sec' => $orders > 0 && (float) $row->handle_weighted > 0
                    ? (int) round((float) $row->handle_weighted / $orders)
                    : null,
                'sla_rate' => (int) $row->sla_total > 0
                    ? round((int) $row->on_time_count / (int) $row->sla_total, 4)
                    : null,
                'replace_rate' => $items > 0 ? round((int) $row->replace_count / $items, 4) : null,
                'rating_avg' => (int) $row->rating_count > 0
                    ? round((float) $row->rating_sum / (int) $row->rating_count, 2)
                    : null,
                'complaint_count' => (int) $row->complaint_count,
                'distance_m' => (int) $row->distance_m,
                'shift_seconds' => (int) $row->shift_seconds,
            ];
        })->values()->all();

        return [
            'period' => $period,
            'store_id' => $storeId,
            'role' => $role,
            'leaderboard' => $leaderboard,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(StaffContext $staff, ?string $storeId, string $period): array
    {
        $stores = $this->allowedStores($staff, $storeId);
        $from = $this->periodStart($period);

        $daily = DB::table('employee_daily_stats')
            ->where('stat_date', '>=', $from->toDateString())
            ->when($stores !== null, fn ($query) => $query->whereIn('store_id', $stores))
            ->get();

        $pickers = $daily->where('role', 'picker');
        $couriers = $daily->where('role', 'courier');

        $slaRate = function ($rows): ?float {
            $total = (int) $rows->sum('sla_total');

            return $total > 0 ? round((int) $rows->sum('on_time_count') / $total, 4) : null;
        };

        [$bottleneck, $loadByHour] = $this->journalBreakdown($stores, $from);

        $dynamics = $daily->groupBy('stat_date')->sortKeys()->map(function ($rows, $date) {
            $items = (int) $rows->sum('items_count');
            $ratingCount = (int) $rows->sum('rating_count');

            return [
                'date' => (string) $date,
                'orders_count' => (int) $rows->where('role', 'picker')->sum('orders_count'),
                'replace_rate' => $items > 0 ? round((int) $rows->sum('replace_count') / $items, 4) : null,
                'remove_rate' => $items > 0 ? round((int) $rows->sum('remove_count') / $items, 4) : null,
                'rating_avg' => $ratingCount > 0 ? round((float) $rows->sum('rating_sum') / $ratingCount, 2) : null,
            ];
        })->values()->all();

        return [
            'period' => $period,
            'store_id' => $storeId,
            'sla' => [
                'picking_rate' => $slaRate($pickers),
                'delivery_rate' => $slaRate($couriers),
                'picking_overdue_count' => (int) $pickers->sum('sla_total') - (int) $pickers->sum('on_time_count'),
                'delivery_overdue_count' => (int) $couriers->sum('sla_total') - (int) $couriers->sum('on_time_count'),
            ],
            'bottleneck' => $bottleneck,
            'load_by_hour' => $loadByHour,
            'dynamics' => $dynamics,
            'totals' => [
                'orders_count' => (int) $pickers->sum('orders_count'),
                'gmv_amount' => round((float) $pickers->sum('gmv_amount'), 2),
                'active_employees' => $daily->pluck('employee_id')->unique()->count(),
            ],
        ];
    }

    /**
     * Bottleneck-этапы «сборка / ожидание курьера / дорога» и нагрузка
     * по часам — из журнала событий за период.
     *
     * @param  list<string>|null  $stores
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function journalBreakdown(?array $stores, CarbonImmutable $from): array
    {
        $events = DB::table('analytics_events')
            ->whereIn('event_type', ['picking.started', 'picking.finished', 'delivery.started', 'delivery.finished'])
            ->where('occurred_at', '>=', $from->utc()->format('Y-m-d H:i:sP'))
            ->when($stores !== null, fn ($query) => $query->whereIn('store_id', $stores))
            ->orderBy('occurred_at')
            ->get();

        $tz = (string) config('analytics.timezone', 'Europe/Moscow');

        $pickStart = [];
        $pickFinish = [];
        $delStart = [];
        $delFinish = [];
        $loadByHour = [];

        foreach ($events as $event) {
            $occurredAt = CarbonImmutable::parse($event->occurred_at);
            $payload = json_decode((string) $event->payload, true) ?: [];

            switch ($event->event_type) {
                case 'picking.started':
                    if ($event->order_id !== null) {
                        $pickStart[$event->order_id] ??= $occurredAt;
                    }
                    break;
                case 'picking.finished':
                    if ($event->order_id !== null) {
                        $pickFinish[$event->order_id] = $occurredAt;
                        $hour = $occurredAt->setTimezone($tz)->format('H');
                        $loadByHour[$hour]['picking'] = ($loadByHour[$hour]['picking'] ?? 0) + 1;
                    }
                    break;
                case 'delivery.started':
                    if (($payload['delivery_id'] ?? null) !== null) {
                        $delStart[(string) $payload['delivery_id']] ??= $occurredAt;
                    }
                    break;
                case 'delivery.finished':
                    if ($event->order_id !== null) {
                        $delFinish[$event->order_id] = [
                            'occurred_at' => $occurredAt,
                            'delivery_id' => isset($payload['delivery_id']) ? (string) $payload['delivery_id'] : null,
                        ];
                        $hour = $occurredAt->setTimezone($tz)->format('H');
                        $loadByHour[$hour]['delivery'] = ($loadByHour[$hour]['delivery'] ?? 0) + 1;
                    }
                    break;
            }
        }

        $pickingSecs = [];
        $waitSecs = [];
        $deliverySecs = [];

        foreach ($pickFinish as $orderId => $finishedAt) {
            if (isset($pickStart[$orderId])) {
                $pickingSecs[] = $pickStart[$orderId]->diffInSeconds($finishedAt);
            }
        }

        foreach ($delFinish as $orderId => $finish) {
            $startedAt = $finish['delivery_id'] !== null ? ($delStart[$finish['delivery_id']] ?? null) : null;

            if ($startedAt !== null) {
                $deliverySecs[] = $startedAt->diffInSeconds($finish['occurred_at']);

                if (isset($pickFinish[$orderId])) {
                    $waitSecs[] = $pickFinish[$orderId]->diffInSeconds($startedAt);
                }
            }
        }

        $avg = fn (array $values): ?int => $values === []
            ? null
            : (int) round(array_sum($values) / count($values));

        ksort($loadByHour);

        return [
            [
                'avg_picking_sec' => $avg($pickingSecs),
                'avg_wait_courier_sec' => $avg($waitSecs),
                'avg_delivery_sec' => $avg($deliverySecs),
            ],
            array_map(
                fn ($hour) => [
                    'hour' => (string) $hour,
                    'picking' => $loadByHour[$hour]['picking'] ?? 0,
                    'delivery' => $loadByHour[$hour]['delivery'] ?? 0,
                ],
                array_keys($loadByHour),
            ),
        ];
    }

    /**
     * Магазины, доступные админу. null = без ограничения (все).
     * Запрошенный store_id вне списка → 403.
     *
     * @return list<string>|null
     */
    private function allowedStores(StaffContext $staff, ?string $storeId): ?array
    {
        $own = $staff->shopCodes;

        if ($storeId !== null) {
            if ($own !== [] && ! in_array($storeId, $own, true)) {
                abort(403, 'Нет доступа к этому магазину');
            }

            return [$storeId];
        }

        return $own === [] ? null : $own;
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, string>
     */
    private function employeeNames(array $employeeIds): array
    {
        $numeric = array_values(array_filter($employeeIds, 'is_numeric'));

        if ($numeric === []) {
            return [];
        }

        return DB::connection('docker')->table('users')
            ->whereIn('id', $numeric)
            ->get(['id', 'name', 'surname'])
            ->mapWithKeys(fn ($user) => [
                (string) $user->id => trim(($user->name ?? '').' '.($user->surname ?? '')),
            ])
            ->all();
    }

    private function periodStart(string $period): CarbonImmutable
    {
        $today = CarbonImmutable::now((string) config('analytics.timezone', 'Europe/Moscow'))->startOfDay();

        return match ($period) {
            'day' => $today,
            'month' => $today->startOfMonth(),
            default => $today->startOfWeek(),
        };
    }
}
