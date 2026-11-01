<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesDockerSchema;
use Tests\TestCase;

/**
 * GET /api/v1/analytics/me (#11597): живой период shift по session_id
 * открытой смены, week/month — из employee_daily_stats.
 */
final class MeStatsApiTest extends TestCase
{
    use CreatesDockerSchema;
    use RefreshDatabase;

    private int $userId;

    private string $bearer;

    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('analytics:ensure-partitions');
        $this->setUpDockerSchema();

        [$this->userId, $this->bearer] = $this->createStaffWithToken(roles: ['collector'], shopCodes: ['shop-a']);
        $this->shiftId = $this->openShift($this->userId);
    }

    private function insertEvent(string $type, CarbonImmutable $at, array $attrs = [], array $payload = []): void
    {
        DB::table('analytics_events')->insert(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'occurred_at' => $at->toIso8601String(),
            'source' => 'delivery',
            'store_id' => 'shop-a',
            'session_id' => 'shift-'.$this->shiftId,
            'employee_id' => (string) $this->userId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ], $attrs));
    }

    public function test_shift_period_computes_live_stats_from_session_events(): void
    {
        $now = CarbonImmutable::now();

        $this->insertEvent('shift.started', $now->subHours(2), ['role' => 'picker']);

        // Заказ O1: 10 минут сборки, в срок
        $this->insertEvent('picking.started', $now->subMinutes(90), ['role' => 'picker', 'order_id' => 'O1']);
        $this->insertEvent('picking.finished', $now->subMinutes(80), ['role' => 'picker', 'order_id' => 'O1'], [
            'assembled_items' => 5, 'cancelled_items' => 0, 'replaced_items' => 1,
            'assembled_amount' => 700.0,
            'slot_start_date' => $now->addHour()->toIso8601String(),
        ]);

        // Заказ O2: 20 минут сборки
        $this->insertEvent('picking.started', $now->subMinutes(60), ['role' => 'picker', 'order_id' => 'O2']);
        $this->insertEvent('picking.finished', $now->subMinutes(40), ['role' => 'picker', 'order_id' => 'O2'], [
            'assembled_items' => 3, 'cancelled_items' => 1, 'replaced_items' => 0,
            'assembled_amount' => 300.0,
            'slot_start_date' => $now->addHour()->toIso8601String(),
        ]);

        // Сканы: qr x3, manual x1
        foreach ([['qr', 'ok'], ['qr', 'ok'], ['qr', 'error'], ['manual', 'ok']] as [$method, $result]) {
            $this->insertEvent('picking.item_scanned', $now->subMinutes(85), ['role' => 'picker', 'order_id' => 'O1'], [
                'method' => $method, 'result' => $result,
            ]);
        }

        $response = $this->getJson('/api/v1/analytics/me?period=shift', ['Authorization' => "Bearer {$this->bearer}"]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.period', 'shift')
            ->assertJsonPath('data.role', 'picker')
            ->assertJsonPath('data.shift_active', true)
            ->assertJsonPath('data.orders_count', 2)
            ->assertJsonPath('data.items_count', 8)
            ->assertJsonPath('data.gmv_amount', 1000)
            ->assertJsonPath('data.avg_handle_sec', 900)
            ->assertJsonPath('data.sla_rate', 1)
            ->assertJsonPath('data.replace_count', 1)
            ->assertJsonPath('data.remove_count', 1)
            ->assertJsonPath('data.scan_qr_count', 3)
            ->assertJsonPath('data.scan_manual_count', 1)
            ->assertJsonPath('data.scan_error_count', 1)
            ->assertJsonPath('data.qr_rate', 0.75);

        // Смена открыта ~2 часа назад (по журналу shift.started)
        $this->assertEqualsWithDelta(7200, $response->json('data.shift_seconds'), 120);
        $this->assertNotEmpty($response->json('data.bars'));
    }

    public function test_shift_without_open_shift_returns_zeros(): void
    {
        DB::connection('docker')->table('shifts')->where('id', $this->shiftId)->update(['finished_at' => now()]);

        $this->getJson('/api/v1/analytics/me?period=shift', ['Authorization' => "Bearer {$this->bearer}"])
            ->assertStatus(200)
            ->assertJsonPath('data.shift_active', false)
            ->assertJsonPath('data.orders_count', 0);
    }

    public function test_week_period_sums_daily_stats(): void
    {
        $tz = config('analytics.timezone');
        $today = CarbonImmutable::now($tz)->startOfWeek();

        foreach ([[0, 5, 50, 480], [1, 7, 70, 600]] as [$offset, $orders, $items, $handle]) {
            DB::table('employee_daily_stats')->insert([
                'employee_id' => (string) $this->userId,
                'role' => 'picker',
                'store_id' => 'shop-a',
                'stat_date' => $today->addDays($offset)->toDateString(),
                'orders_count' => $orders,
                'items_count' => $items,
                'gmv_amount' => $orders * 100,
                'avg_handle_sec' => $handle,
                'on_time_count' => $orders - 1,
                'sla_total' => $orders,
                'replace_count' => 1,
                'remove_count' => 0,
                'scan_qr_count' => 10,
                'scan_manual_count' => 0,
                'scan_error_count' => 0,
                'rating_sum' => 9,
                'rating_count' => 2,
                'complaint_count' => 0,
                'distance_m' => 0,
                'shift_seconds' => 21600,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson('/api/v1/analytics/me?period=week', ['Authorization' => "Bearer {$this->bearer}"]);

        $response->assertStatus(200)
            ->assertJsonPath('data.period', 'week')
            ->assertJsonPath('data.orders_count', 12)
            ->assertJsonPath('data.items_count', 120)
            ->assertJsonPath('data.gmv_amount', 1200)
            // (480*5 + 600*7) / 12 = 550
            ->assertJsonPath('data.avg_handle_sec', 550)
            ->assertJsonPath('data.rating_avg', 4.5)
            ->assertJsonPath('data.shift_seconds', 43200)
            ->assertJsonPath('data.personal_record', 7)
            ->assertJsonPath('data.vs_team_percentile', 1);

        $this->assertCount(2, $response->json('data.bars'));
    }
}
