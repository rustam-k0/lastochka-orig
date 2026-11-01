<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmployeeDailyStat;
use App\Services\DailyStatsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Агрегация журнала analytics_events в employee_daily_stats (#11596).
 *
 * Сценарий дня: сборщик собирает 2 заказа (тайминги, слоты, сканы),
 * курьер доставляет 1 заказ (тайминг, слот, пробег), клиент ставит
 * 2 оценки (5 и 2), смена сборщика закрывается. Пересчёт идемпотентен.
 */
final class DailyStatsAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-14';

    private const TZ = 'Europe/Moscow';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('analytics:ensure-partitions');
    }

    private function insertEvent(string $type, string $time, array $attrs = [], array $payload = []): void
    {
        DB::table('analytics_events')->insert(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'occurred_at' => CarbonImmutable::parse(self::DATE.' '.$time, self::TZ)->toIso8601String(),
            'source' => 'delivery',
            'store_id' => 'shop-a',
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ], $attrs));
    }

    private function seedDay(): void
    {
        // --- Сборщик 3: заказ O1 (10:00 → 10:08, слот 11:00 — успел) ---
        $this->insertEvent('picking.started', '10:00:00', [
            'employee_id' => '3', 'role' => 'picker', 'order_id' => 'O1', 'session_id' => 'shift-15',
        ]);
        $this->insertEvent('picking.finished', '10:08:00', [
            'employee_id' => '3', 'role' => 'picker', 'order_id' => 'O1', 'session_id' => 'shift-15',
        ], [
            'assembled_items' => 5, 'cancelled_items' => 1, 'replaced_items' => 1,
            'ordered_items' => 6, 'assembled_amount' => 1000.0,
            'slot_start_date' => '2026-07-14T11:00:00+03:00',
            'slot_end_date' => '2026-07-14T13:00:00+03:00',
        ]);

        // --- Сборщик 3: заказ O2 (11:00 → 11:20, слот 11:10 — опоздал) ---
        $this->insertEvent('picking.started', '11:00:00', [
            'employee_id' => '3', 'role' => 'picker', 'order_id' => 'O2',
        ]);
        $this->insertEvent('picking.finished', '11:20:00', [
            'employee_id' => '3', 'role' => 'picker', 'order_id' => 'O2',
        ], [
            'assembled_items' => 3, 'cancelled_items' => 0, 'replaced_items' => 0,
            'ordered_items' => 3, 'assembled_amount' => 500.0,
            'slot_start_date' => '2026-07-14T11:10:00+03:00',
            'slot_end_date' => '2026-07-14T13:00:00+03:00',
        ]);

        // --- Сканы сборщика 3: qr ok x2, manual ok x1, qr error x1 ---
        foreach ([['qr', 'ok', '10:01:00'], ['qr', 'ok', '10:02:00'], ['manual', 'ok', '10:03:00'], ['qr', 'error', '10:04:00']] as [$method, $result, $time]) {
            $this->insertEvent('picking.item_scanned', $time, [
                'employee_id' => '3', 'role' => 'picker', 'order_id' => 'O1',
            ], ['method' => $method, 'result' => $result]);
        }

        // --- Курьер 9: доставка O1 (12:00 → 12:25, слот до 12:30 — успел) ---
        $this->insertEvent('delivery.started', '12:00:00', [
            'employee_id' => '9', 'role' => 'courier', 'order_id' => 'O1',
        ], ['delivery_id' => 7]);
        $this->insertEvent('delivery.finished', '12:25:00', [
            'employee_id' => '9', 'role' => 'courier', 'order_id' => 'O1',
        ], [
            'delivery_id' => 7, 'order_amount' => 1000.0,
            'slot_end_date' => '2026-07-14T12:30:00+03:00',
        ]);
        $this->insertEvent('delivery.distance_logged', '12:26:00', [
            'employee_id' => '9', 'role' => 'courier', 'order_id' => 'O1',
        ], ['delivery_id' => 7, 'distance_m' => 3800]);

        // --- Оценки клиентов: O1 = 5 звёзд, O2 = 2 (жалоба) ---
        $this->insertEvent('rating.received', '13:00:00', [
            'role' => 'client', 'order_id' => 'O1', 'client_id' => '42', 'source' => 'storefront',
        ], ['rating' => 5]);
        $this->insertEvent('rating.received', '13:30:00', [
            'role' => 'client', 'order_id' => 'O2', 'client_id' => '43', 'source' => 'storefront',
        ], ['rating' => 2]);

        // --- Смена сборщика 3 закрыта (6 часов) ---
        $this->insertEvent('shift.ended', '16:00:00', [
            'employee_id' => '3', 'role' => 'picker', 'session_id' => 'shift-15',
        ], ['shift_seconds' => 21600]);
    }

    public function test_aggregates_picker_and_courier_day(): void
    {
        $this->seedDay();

        $count = app(DailyStatsAggregator::class)
            ->aggregateDate(CarbonImmutable::parse(self::DATE, self::TZ));

        $this->assertSame(2, $count);

        $picker = EmployeeDailyStat::where('employee_id', '3')->where('role', 'picker')->firstOrFail();

        $this->assertSame(self::DATE, $picker->stat_date->toDateString());
        $this->assertSame('shop-a', $picker->store_id);
        $this->assertSame(2, $picker->orders_count);
        $this->assertSame(8, $picker->items_count);
        $this->assertSame(1500.0, $picker->gmv_amount);
        // (480 + 1200) / 2
        $this->assertSame(840, $picker->avg_handle_sec);
        $this->assertSame(1, $picker->on_time_count);
        $this->assertSame(2, $picker->sla_total);
        $this->assertSame(1, $picker->replace_count);
        $this->assertSame(1, $picker->remove_count);
        $this->assertSame(3, $picker->scan_qr_count);
        $this->assertSame(1, $picker->scan_manual_count);
        $this->assertSame(1, $picker->scan_error_count);
        // Оценки O1 (5) и O2 (2) атрибуцированы сборщику по picking.finished
        $this->assertSame(7.0, $picker->rating_sum);
        $this->assertSame(2, $picker->rating_count);
        $this->assertSame(1, $picker->complaint_count);
        $this->assertSame(21600, $picker->shift_seconds);

        $courier = EmployeeDailyStat::where('employee_id', '9')->where('role', 'courier')->firstOrFail();

        $this->assertSame(1, $courier->orders_count);
        $this->assertSame(1000.0, $courier->gmv_amount);
        $this->assertSame(1500, $courier->avg_handle_sec);
        $this->assertSame(1, $courier->on_time_count);
        $this->assertSame(1, $courier->sla_total);
        $this->assertSame(3800, (int) $courier->distance_m);
        // Курьеру атрибуцирована только оценка O1 (доставку O2 он не вёз)
        $this->assertSame(5.0, $courier->rating_sum);
        $this->assertSame(1, $courier->rating_count);
        $this->assertSame(0, $courier->complaint_count);
        $this->assertSame(0, $courier->shift_seconds);
    }

    public function test_reaggregation_is_idempotent(): void
    {
        $this->seedDay();

        $aggregator = app(DailyStatsAggregator::class);
        $date = CarbonImmutable::parse(self::DATE, self::TZ);

        $aggregator->aggregateDate($date);
        $aggregator->aggregateDate($date);

        $this->assertSame(2, EmployeeDailyStat::count());
        $this->assertSame(2, EmployeeDailyStat::where('employee_id', '3')->value('orders_count'));
    }

    public function test_rating_updated_overrides_received_same_day(): void
    {
        $this->seedDay();

        // Клиент передумал: оценка O1 обновлена с 5 на 3 в тот же день
        $this->insertEvent('rating.updated', '14:00:00', [
            'role' => 'client', 'order_id' => 'O1', 'client_id' => '42', 'source' => 'storefront',
        ], ['rating' => 3]);

        app(DailyStatsAggregator::class)
            ->aggregateDate(CarbonImmutable::parse(self::DATE, self::TZ));

        $picker = EmployeeDailyStat::where('employee_id', '3')->where('role', 'picker')->firstOrFail();

        // O1 = 3 (последняя за день), O2 = 2 → сумма 5, жалоба одна (O2)
        $this->assertSame(5.0, $picker->rating_sum);
        $this->assertSame(2, $picker->rating_count);
        $this->assertSame(1, $picker->complaint_count);
    }

    public function test_command_aggregates_multiple_days(): void
    {
        $this->seedDay();

        $this->artisan('analytics:aggregate-daily', ['--date' => self::DATE])
            ->assertExitCode(0);

        $this->assertSame(2, EmployeeDailyStat::count());
    }
}
