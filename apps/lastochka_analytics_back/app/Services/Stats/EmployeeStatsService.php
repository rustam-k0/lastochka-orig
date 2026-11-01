<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Support\StaffContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Личная статистика сотрудника для GET /api/v1/analytics/me (#11597/#11602).
 *
 * Периоды week/month читаются из employee_daily_stats (пересчитывается
 * по расписанию), период shift — живой расчёт по журналу analytics_events
 * с фильтром session_id = 'shift-<id>' открытой смены (кэш 60 сек).
 */
final class EmployeeStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function me(StaffContext $staff, string $period, ?string $roleParam): array
    {
        $role = $this->resolveRole($staff, $roleParam);

        $data = match ($period) {
            'shift' => $this->liveShift($staff, $role),
            'week' => $this->ranged($staff, $role, $this->today()->startOfWeek(), 'week'),
            'month' => $this->ranged($staff, $role, $this->today()->startOfMonth(), 'month'),
        };

        return array_merge([
            'period' => $period,
            'role' => $role,
            'personal_record' => $this->personalRecord($staff->id, $role),
        ], $data);
    }

    public function resolveRole(StaffContext $staff, ?string $roleParam): string
    {
        if (in_array($roleParam, ['picker', 'courier'], true)) {
            return $roleParam;
        }

        return ($staff->isCourier() && ! $staff->isPicker()) ? 'courier' : 'picker';
    }

    /**
     * Живая статистика текущей смены. Открытая смена берётся из docker-БД
     * (авторитетный источник), события — по колонке session_id журнала.
     *
     * @return array<string, mixed>
     */
    public function liveShift(StaffContext $staff, string $role): array
    {
        $shift = DB::connection('docker')->table('shifts')
            ->where('user_id', $staff->id)
            ->whereNull('finished_at')
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        if ($shift === null) {
            return array_merge(
                ['shift_active' => false, 'bars' => []],
                $this->metrics(
                    $role,
                    [],
                    [],
                    ['qr' => 0, 'manual' => 0, 'error' => 0],
                    $this->todayAggregateRatings($staff->id, $role),
                    0,
                ),
                ['vs_team_percentile' => null],
            );
        }

        $session = 'shift-'.$shift->id;
        $ttl = (int) config('analytics.live_cache_seconds', 60);

        return Cache::remember(
            "analytics:me:{$staff->id}:{$role}:{$session}",
            $ttl,
            function () use ($staff, $role, $session, $shift) {
                $events = DB::table('analytics_events')
                    ->where('session_id', $session)
                    ->whereIn('event_type', [
                        'shift.started',
                        'picking.started', 'picking.finished', 'picking.item_scanned',
                        'delivery.started', 'delivery.finished', 'delivery.distance_logged',
                    ])
                    ->orderBy('occurred_at')
                    ->get();

                [$finished, $handles, $scans, $shiftSeconds] = $this->collectSession($events, $role);

                return array_merge(
                    ['shift_active' => true, 'shift_id' => (int) $shift->id],
                    $this->metrics(
                        $role,
                        $finished,
                        $handles,
                        $scans,
                        $this->todayAggregateRatings($staff->id, $role),
                        $shiftSeconds,
                    ),
                    [
                        'bars' => $this->hourlyBars($finished),
                        'vs_team_percentile' => $this->percentile(
                            $staff->id,
                            $role,
                            $this->today(),
                            count($finished),
                        ),
                    ],
                );
            },
        );
    }

    /**
     * Период из дневных агрегатов: с $from по сегодня включительно.
     *
     * @return array<string, mixed>
     */
    private function ranged(StaffContext $staff, string $role, CarbonImmutable $from, string $granularity): array
    {
        $rows = DB::table('employee_daily_stats')
            ->where('employee_id', (string) $staff->id)
            ->where('role', $role)
            ->where('stat_date', '>=', $from->toDateString())
            ->orderBy('stat_date')
            ->get();

        $sum = fn (string $col) => $rows->sum(fn ($row) => (float) $row->{$col});

        $orders = (int) $sum('orders_count');
        $items = (int) $sum('items_count');
        $handleWeighted = $rows->sum(fn ($row) => ($row->avg_handle_sec ?? 0) * $row->orders_count);

        $bars = $granularity === 'week'
            ? $rows->map(fn ($row) => ['label' => (string) $row->stat_date, 'value' => (int) $row->orders_count])->values()->all()
            : $rows->groupBy(fn ($row) => 'W'.CarbonImmutable::parse((string) $row->stat_date)->isoWeek())
                ->map(fn ($group, $week) => ['label' => $week, 'value' => (int) $group->sum('orders_count')])
                ->values()->all();

        return array_merge(
            [
                'orders_count' => $orders,
                'items_count' => $items,
                'gmv_amount' => round($sum('gmv_amount'), 2),
                'avg_handle_sec' => $orders > 0 && $handleWeighted > 0 ? (int) round($handleWeighted / $orders) : null,
                'shift_seconds' => (int) $sum('shift_seconds'),
                'distance_m' => (int) $sum('distance_m'),
            ],
            $this->rates(
                $items,
                (int) $sum('on_time_count'),
                (int) $sum('sla_total'),
                (int) $sum('replace_count'),
                (int) $sum('remove_count'),
                (int) $sum('scan_qr_count'),
                (int) $sum('scan_manual_count'),
                (int) $sum('scan_error_count'),
                (float) $sum('rating_sum'),
                (int) $sum('rating_count'),
                (int) $sum('complaint_count'),
                $orders,
                (int) $sum('shift_seconds'),
            ),
            [
                'bars' => $bars,
                'vs_team_percentile' => $this->percentile($staff->id, $role, $from, $orders),
            ],
        );
    }

    /**
     * Свёртка событий сессии: завершённые заказы, тайминги, сканы, стаж смены.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: list<int>, 2: array{qr: int, manual: int, error: int}, 3: int}
     */
    private function collectSession($events, string $role): array
    {
        $startedByKey = [];
        $finished = [];
        $handles = [];
        $scans = ['qr' => 0, 'manual' => 0, 'error' => 0];
        $shiftSeconds = 0;

        $finishType = $role === 'courier' ? 'delivery.finished' : 'picking.finished';
        $startType = $role === 'courier' ? 'delivery.started' : 'picking.started';

        foreach ($events as $event) {
            $payload = json_decode((string) $event->payload, true) ?: [];
            $occurredAt = CarbonImmutable::parse($event->occurred_at);

            if ($event->event_type === 'shift.started') {
                $shiftSeconds = (int) $occurredAt->diffInSeconds(CarbonImmutable::now());

                continue;
            }

            if ($event->event_type === 'picking.item_scanned' && $role === 'picker') {
                if (($payload['method'] ?? null) === 'qr') {
                    $scans['qr']++;
                } elseif (($payload['method'] ?? null) === 'manual') {
                    $scans['manual']++;
                }
                if (($payload['result'] ?? null) === 'error') {
                    $scans['error']++;
                }

                continue;
            }

            if ($event->event_type === $startType) {
                $key = $role === 'courier' ? ($payload['delivery_id'] ?? null) : $event->order_id;
                if ($key !== null) {
                    $startedByKey[(string) $key] ??= $occurredAt;
                }

                continue;
            }

            if ($event->event_type === $finishType && $event->order_id !== null) {
                $finished[(string) $event->order_id] = [
                    'payload' => $payload,
                    'occurred_at' => $occurredAt,
                ];

                continue;
            }

            if ($event->event_type === 'delivery.distance_logged' && $role === 'courier' && $event->order_id !== null) {
                $finished[(string) $event->order_id]['distance_m'] = (int) ($payload['distance_m'] ?? 0);
            }
        }

        foreach ($finished as $orderId => $data) {
            if (! isset($data['payload'])) {
                continue;
            }

            $key = $role === 'courier'
                ? (string) ($data['payload']['delivery_id'] ?? '')
                : (string) $orderId;

            if (isset($startedByKey[$key])) {
                $handles[] = (int) $startedByKey[$key]->diffInSeconds($data['occurred_at']);
            }
        }

        // Заказ без picking/delivery.finished (только distance) — не считается заказом
        $finished = array_filter($finished, fn ($data) => isset($data['payload']));

        return [$finished, $handles, $scans, $shiftSeconds];
    }

    /**
     * Метрики живой смены из свёрнутых событий сессии.
     *
     * @param  array<string, array<string, mixed>>  $finished
     * @param  list<int>  $handles
     * @param  array{qr: int, manual: int, error: int}  $scans
     * @param  array{rating_sum: float, rating_count: int, complaint_count: int}  $ratings
     * @return array<string, mixed>
     */
    private function metrics(string $role, array $finished, array $handles, array $scans, array $ratings, int $shiftSeconds): array
    {
        $orders = count($finished);
        $items = 0;
        $gmv = 0.0;
        $onTime = 0;
        $slaTotal = 0;
        $replace = 0;
        $remove = 0;
        $distance = 0;

        $tz = (string) config('analytics.timezone', 'Europe/Moscow');

        foreach ($finished as $data) {
            $payload = $data['payload'];

            if ($role === 'picker') {
                $items += (int) ($payload['assembled_items'] ?? 0);
                $gmv += (float) ($payload['assembled_amount'] ?? 0);
                $replace += (int) ($payload['replaced_items'] ?? 0);
                $remove += (int) ($payload['cancelled_items'] ?? 0);
                $slot = $payload['slot_start_date'] ?? null;
            } else {
                $gmv += (float) ($payload['order_amount'] ?? 0);
                $distance += (int) ($data['distance_m'] ?? 0);
                $slot = $payload['slot_end_date'] ?? null;
            }

            if (is_string($slot) && trim($slot) !== '') {
                try {
                    $slotAt = CarbonImmutable::parse($slot, $tz);
                    $slaTotal++;
                    if ($data['occurred_at']->lessThanOrEqualTo($slotAt)) {
                        $onTime++;
                    }
                } catch (\Throwable) {
                    // битый слот не считаем в SLA
                }
            }
        }

        return array_merge(
            [
                'orders_count' => $orders,
                'items_count' => $items,
                'gmv_amount' => round($gmv, 2),
                'avg_handle_sec' => $handles === [] ? null : (int) round(array_sum($handles) / count($handles)),
                'shift_seconds' => $shiftSeconds,
                'distance_m' => $distance,
            ],
            $this->rates(
                $items,
                $onTime,
                $slaTotal,
                $replace,
                $remove,
                $scans['qr'],
                $scans['manual'],
                $scans['error'],
                $ratings['rating_sum'],
                $ratings['rating_count'],
                $ratings['complaint_count'],
                $orders,
                $shiftSeconds,
            ),
        );
    }

    /**
     * Производные метрики — формулы из ТЗ #11596.
     *
     * @return array<string, mixed>
     */
    private function rates(
        int $items,
        int $onTime,
        int $slaTotal,
        int $replace,
        int $remove,
        int $scanQr,
        int $scanManual,
        int $scanError,
        float $ratingSum,
        int $ratingCount,
        int $complaints,
        int $orders,
        int $shiftSeconds,
    ): array {
        $scansTotal = $scanQr + $scanManual;

        return [
            'sla_rate' => $slaTotal > 0 ? round($onTime / $slaTotal, 4) : null,
            'accuracy' => ($items > 0 && $scansTotal > 0) ? round(1 - $scanError / $items, 4) : null,
            'replace_rate' => $items > 0 ? round($replace / $items, 4) : null,
            'remove_rate' => $items > 0 ? round($remove / $items, 4) : null,
            'qr_rate' => $scansTotal > 0 ? round($scanQr / $scansTotal, 4) : null,
            'scan_qr_count' => $scanQr,
            'scan_manual_count' => $scanManual,
            'scan_error_count' => $scanError,
            'replace_count' => $replace,
            'remove_count' => $remove,
            'on_time_count' => $onTime,
            'sla_total' => $slaTotal,
            'rating_avg' => $ratingCount > 0 ? round($ratingSum / $ratingCount, 2) : null,
            'rating_count' => $ratingCount,
            'complaint_count' => $complaints,
            'orders_per_hour' => $shiftSeconds > 0 ? round($orders / ($shiftSeconds / 3600), 2) : null,
        ];
    }

    /**
     * Рейтинг за сегодня из агрегата (для живой смены: атрибуция оценок
     * делается только агрегатором, пересчитывается каждые 15 минут).
     *
     * @return array{rating_sum: float, rating_count: int, complaint_count: int}
     */
    private function todayAggregateRatings(int $employeeId, string $role): array
    {
        $row = DB::table('employee_daily_stats')
            ->where('employee_id', (string) $employeeId)
            ->where('role', $role)
            ->where('stat_date', $this->today()->toDateString())
            ->first();

        return [
            'rating_sum' => (float) ($row->rating_sum ?? 0),
            'rating_count' => (int) ($row->rating_count ?? 0),
            'complaint_count' => (int) ($row->complaint_count ?? 0),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $finished
     * @return list<array{label: string, value: int}>
     */
    private function hourlyBars(array $finished): array
    {
        $tz = (string) config('analytics.timezone', 'Europe/Moscow');
        $byHour = [];

        foreach ($finished as $data) {
            $hour = $data['occurred_at']->setTimezone($tz)->format('H');
            $byHour[$hour] = ($byHour[$hour] ?? 0) + 1;
        }

        ksort($byHour);

        return array_map(
            fn ($hour) => ['label' => (string) $hour, 'value' => $byHour[$hour]],
            array_keys($byHour),
        );
    }

    public function personalRecord(int $employeeId, string $role): int
    {
        return (int) DB::table('employee_daily_stats')
            ->where('employee_id', (string) $employeeId)
            ->where('role', $role)
            ->max('orders_count');
    }

    /**
     * Доля коллег (та же роль и магазин, тот же период), чей результат
     * не выше моего. Магазин — по последней строке агрегата сотрудника.
     */
    public function percentile(int $employeeId, string $role, CarbonImmutable $from, int $myOrders): ?float
    {
        $store = DB::table('employee_daily_stats')
            ->where('employee_id', (string) $employeeId)
            ->where('role', $role)
            ->orderByDesc('stat_date')
            ->value('store_id');

        $peers = DB::table('employee_daily_stats')
            ->selectRaw('employee_id, SUM(orders_count) AS total')
            ->where('role', $role)
            ->where('stat_date', '>=', $from->toDateString())
            ->when($store !== null, fn ($query) => $query->where('store_id', $store))
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');

        if ($peers->isEmpty()) {
            return null;
        }

        // Живая смена может опережать агрегат — свой результат берём максимумом
        $mine = max($myOrders, (int) ($peers[(string) $employeeId] ?? 0));
        $notAbove = $peers->filter(fn ($total) => (int) $total <= $mine)->count();

        if (! isset($peers[(string) $employeeId])) {
            $notAbove++;
        }

        $total = $peers->count() + (isset($peers[(string) $employeeId]) ? 0 : 1);

        return round($notAbove / $total, 2);
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('analytics.timezone', 'Europe/Moscow'))->startOfDay();
    }
}
