<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Полный идемпотентный пересчёт employee_daily_stats за календарный день
 * (#11596, эпик B2E #11595).
 *
 * Источник — журнал analytics_events. День считается в analytics.timezone.
 * Правила метрик:
 *  - picker: заказы/позиции/GMV/замены/отмены из picking.finished (последнего
 *    по заказу), время сборки = picking.finished − picking.started,
 *    SLA = собран до slot_start_date; сканы из picking.item_scanned;
 *  - courier: заказы/GMV из delivery.finished, время = finished − started
 *    (по delivery_id), SLA = доставлен до slot_end_date, пробег из
 *    delivery.distance_logged (последний по заказу);
 *  - рейтинг: последняя оценка заказа за день (rating.received/updated),
 *    атрибуция сборщику/курьеру по журналу picking.finished /
 *    delivery.finished (fallback delivery.courier_assigned); жалоба =
 *    оценка ≤ analytics.complaint_max_rating;
 *  - shift_seconds: сумма из shift.ended за день.
 */
final class DailyStatsAggregator
{
    private const EVENT_TYPES = [
        'picking.started',
        'picking.finished',
        'picking.item_scanned',
        'delivery.started',
        'delivery.finished',
        'delivery.courier_assigned',
        'delivery.distance_logged',
        'rating.received',
        'rating.updated',
        'shift.ended',
    ];

    /**
     * @return int Сколько строк агрегата записано
     */
    public function aggregateDate(CarbonImmutable $date): int
    {
        $tz = (string) config('analytics.timezone', 'Europe/Moscow');
        $from = $date->setTimezone($tz)->startOfDay();
        $to = $from->addDay();

        // Границы дня — строками со смещением: naive-timestamp Postgres
        // интерпретирует session-таймзоной, а она на серверах разная.
        $events = DB::table('analytics_events')
            ->whereIn('event_type', self::EVENT_TYPES)
            ->where('occurred_at', '>=', $from->utc()->format('Y-m-d H:i:sP'))
            ->where('occurred_at', '<', $to->utc()->format('Y-m-d H:i:sP'))
            ->orderBy('occurred_at')
            ->get();

        $stats = [];          // "emp|role" => метрики
        $pickingStarted = []; // "emp|order" => CarbonImmutable (первый старт)
        $pickingFinished = []; // "emp|order" => event (последний финиш)
        $deliveryStarted = []; // "emp|delivery" => CarbonImmutable
        $deliveryFinished = []; // "emp|order" => event
        $distanceByOrder = []; // "emp|order" => метры (последний агрегат)
        $ratingByOrder = [];   // order => stars (последняя оценка дня)

        foreach ($events as $event) {
            $payload = json_decode((string) $event->payload, true) ?: [];
            $occurredAt = CarbonImmutable::parse($event->occurred_at);
            $employee = $event->employee_id !== null ? (string) $event->employee_id : null;

            switch ($event->event_type) {
                case 'picking.started':
                    if ($employee !== null && $event->order_id !== null) {
                        $pickingStarted["{$employee}|{$event->order_id}"] ??= $occurredAt;
                    }
                    break;

                case 'picking.finished':
                    if ($employee !== null && $event->order_id !== null) {
                        $pickingFinished["{$employee}|{$event->order_id}"] = [$event, $payload, $occurredAt];
                    }
                    break;

                case 'picking.item_scanned':
                    if ($employee !== null) {
                        $row = &$this->row($stats, $employee, 'picker', $event->store_id);
                        if (($payload['method'] ?? null) === 'qr') {
                            $row['scan_qr_count']++;
                        } elseif (($payload['method'] ?? null) === 'manual') {
                            $row['scan_manual_count']++;
                        }
                        if (($payload['result'] ?? null) === 'error') {
                            $row['scan_error_count']++;
                        }
                        unset($row);
                    }
                    break;

                case 'delivery.started':
                    $deliveryId = $payload['delivery_id'] ?? null;
                    if ($employee !== null && $deliveryId !== null) {
                        $deliveryStarted["{$employee}|{$deliveryId}"] ??= $occurredAt;
                    }
                    break;

                case 'delivery.finished':
                    if ($employee !== null && $event->order_id !== null) {
                        $deliveryFinished["{$employee}|{$event->order_id}"] = [$event, $payload, $occurredAt];
                    }
                    break;

                case 'delivery.distance_logged':
                    if ($employee !== null && $event->order_id !== null) {
                        $distanceByOrder["{$employee}|{$event->order_id}"] = (int) ($payload['distance_m'] ?? 0);
                    }
                    break;

                case 'rating.received':
                case 'rating.updated':
                    if ($event->order_id !== null && isset($payload['rating'])) {
                        $ratingByOrder[$event->order_id] = (int) $payload['rating'];
                    }
                    break;

                case 'shift.ended':
                    if ($employee !== null) {
                        $role = $event->role === 'courier' ? 'courier' : 'picker';
                        $row = &$this->row($stats, $employee, $role, $event->store_id);
                        $row['shift_seconds'] += (int) ($payload['shift_seconds'] ?? 0);
                        unset($row);
                    }
                    break;
            }
        }

        $this->applyPicking($stats, $pickingStarted, $pickingFinished);
        $this->applyDelivery($stats, $deliveryStarted, $deliveryFinished, $distanceByOrder);
        $this->applyRatings($stats, $ratingByOrder);

        return $this->persist($stats, $from);
    }

    /**
     * @param  array<string, array<string, mixed>>  $stats
     */
    private function &row(array &$stats, string $employee, string $role, ?string $storeId): array
    {
        $key = "{$employee}|{$role}";

        if (! isset($stats[$key])) {
            $stats[$key] = [
                'employee_id' => $employee,
                'role' => $role,
                'store_id' => $storeId,
                'orders_count' => 0,
                'items_count' => 0,
                'gmv_amount' => 0.0,
                'handle_seconds' => [],
                'on_time_count' => 0,
                'sla_total' => 0,
                'replace_count' => 0,
                'remove_count' => 0,
                'scan_qr_count' => 0,
                'scan_manual_count' => 0,
                'scan_error_count' => 0,
                'rating_sum' => 0.0,
                'rating_count' => 0,
                'complaint_count' => 0,
                'distance_m' => 0,
                'shift_seconds' => 0,
            ];
        }

        if ($storeId !== null) {
            $stats[$key]['store_id'] = $storeId;
        }

        return $stats[$key];
    }

    private function applyPicking(array &$stats, array $started, array $finished): void
    {
        foreach ($finished as $key => [$event, $payload, $occurredAt]) {
            $employee = (string) $event->employee_id;
            $row = &$this->row($stats, $employee, 'picker', $event->store_id);

            $row['orders_count']++;
            $row['items_count'] += (int) ($payload['assembled_items'] ?? 0);
            $row['gmv_amount'] += (float) ($payload['assembled_amount'] ?? 0);
            $row['replace_count'] += (int) ($payload['replaced_items'] ?? 0);
            $row['remove_count'] += (int) ($payload['cancelled_items'] ?? 0);

            if (isset($started[$key])) {
                $row['handle_seconds'][] = $started[$key]->diffInSeconds($occurredAt);
            }

            $slotStart = $this->parseSlot($payload['slot_start_date'] ?? null);
            if ($slotStart !== null) {
                $row['sla_total']++;
                if ($occurredAt->lessThanOrEqualTo($slotStart)) {
                    $row['on_time_count']++;
                }
            }

            unset($row);
        }
    }

    private function applyDelivery(array &$stats, array $started, array $finished, array $distance): void
    {
        foreach ($finished as $key => [$event, $payload, $occurredAt]) {
            $employee = (string) $event->employee_id;
            $row = &$this->row($stats, $employee, 'courier', $event->store_id);

            $row['orders_count']++;
            $row['gmv_amount'] += (float) ($payload['order_amount'] ?? 0);

            $deliveryId = $payload['delivery_id'] ?? null;
            if ($deliveryId !== null && isset($started["{$employee}|{$deliveryId}"])) {
                $row['handle_seconds'][] = $started["{$employee}|{$deliveryId}"]->diffInSeconds($occurredAt);
            }

            $slotEnd = $this->parseSlot($payload['slot_end_date'] ?? null);
            if ($slotEnd !== null) {
                $row['sla_total']++;
                if ($occurredAt->lessThanOrEqualTo($slotEnd)) {
                    $row['on_time_count']++;
                }
            }

            unset($row);
        }

        foreach ($distance as $key => $meters) {
            [$employee] = explode('|', $key, 2);
            $row = &$this->row($stats, $employee, 'courier', null);
            $row['distance_m'] += $meters;
            unset($row);
        }
    }

    /**
     * Атрибуция оценок сотрудникам по журналу (весь журнал, не только день:
     * оценку могут поставить позже дня сборки/доставки).
     */
    private function applyRatings(array &$stats, array $ratingByOrder): void
    {
        if ($ratingByOrder === []) {
            return;
        }

        $orderIds = array_map('strval', array_keys($ratingByOrder));
        $complaintMax = (int) config('analytics.complaint_max_rating', 2);

        $pickerByOrder = $this->latestEmployeeByOrder($orderIds, ['picking.finished']);
        $courierByOrder = $this->latestEmployeeByOrder($orderIds, ['delivery.finished', 'delivery.courier_assigned']);

        foreach ($ratingByOrder as $orderId => $stars) {
            foreach ([['picker', $pickerByOrder], ['courier', $courierByOrder]] as [$role, $map]) {
                $employee = $map[(string) $orderId] ?? null;

                if ($employee === null) {
                    continue;
                }

                $row = &$this->row($stats, (string) $employee, $role, null);
                $row['rating_sum'] += $stars;
                $row['rating_count']++;
                if ($stars <= $complaintMax) {
                    $row['complaint_count']++;
                }
                unset($row);
            }
        }
    }

    /**
     * order_id => employee_id последнего события заказа из $eventTypes
     * (типы в порядке приоритета: следующий — fallback для заказов без первого).
     *
     * @param  list<string>  $orderIds
     * @param  list<string>  $eventTypes
     * @return array<string, string>
     */
    private function latestEmployeeByOrder(array $orderIds, array $eventTypes): array
    {
        $map = [];

        foreach ($eventTypes as $eventType) {
            $missing = array_values(array_diff($orderIds, array_keys($map)));

            if ($missing === []) {
                break;
            }

            $rows = DB::table('analytics_events')
                ->select('order_id', 'employee_id')
                ->whereIn('order_id', $missing)
                ->where('event_type', $eventType)
                ->whereNotNull('employee_id')
                ->orderByDesc('occurred_at')
                ->get();

            foreach ($rows as $row) {
                $map[(string) $row->order_id] ??= (string) $row->employee_id;
            }
        }

        return $map;
    }

    private function parseSlot(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // Naive-строка docker-БД трактуется в отчётной таймзоне
            return CarbonImmutable::parse($value, (string) config('analytics.timezone', 'Europe/Moscow'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $stats
     */
    private function persist(array $stats, CarbonImmutable $day): int
    {
        $statDate = $day->toDateString();

        $rows = array_map(function (array $row) use ($statDate) {
            $handles = $row['handle_seconds'];
            unset($row['handle_seconds']);

            return array_merge($row, [
                'stat_date' => $statDate,
                'avg_handle_sec' => $handles === []
                    ? null
                    : (int) round(array_sum($handles) / count($handles)),
                'gmv_amount' => round($row['gmv_amount'], 2),
                'rating_sum' => round($row['rating_sum'], 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, array_values($stats));

        DB::transaction(function () use ($statDate, $rows): void {
            DB::table('employee_daily_stats')->where('stat_date', $statDate)->delete();

            if ($rows !== []) {
                DB::table('employee_daily_stats')->insert($rows);
            }
        });

        return count($rows);
    }
}
