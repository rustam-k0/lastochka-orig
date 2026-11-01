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
 * GET /api/v1/analytics/employees и /store/summary (#11603):
 * лидерборд, сводка, scope-ограничение админа магазина.
 */
final class StoreStatsApiTest extends TestCase
{
    use CreatesDockerSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('analytics:ensure-partitions');
        $this->setUpDockerSchema();
    }

    private function seedDailyStats(): array
    {
        $docker = DB::connection('docker');

        $picker1 = (int) $docker->table('users')->insertGetId(['name' => 'Первый', 'surname' => 'Сборщиков']);
        $picker2 = (int) $docker->table('users')->insertGetId(['name' => 'Второй', 'surname' => 'Сборщиков']);
        $picker3 = (int) $docker->table('users')->insertGetId(['name' => 'Третий', 'surname' => 'Сборщиков']);

        $today = CarbonImmutable::now(config('analytics.timezone'))->toDateString();

        foreach ([
            [$picker1, 'shop-a', 10, 100, 480],
            [$picker2, 'shop-a', 6, 60, 520],
            [$picker3, 'shop-b', 4, 40, 700],
        ] as [$employeeId, $store, $orders, $items, $handle]) {
            DB::table('employee_daily_stats')->insert([
                'employee_id' => (string) $employeeId,
                'role' => 'picker',
                'store_id' => $store,
                'stat_date' => $today,
                'orders_count' => $orders,
                'items_count' => $items,
                'gmv_amount' => $orders * 100,
                'avg_handle_sec' => $handle,
                'on_time_count' => $orders - 2,
                'sla_total' => $orders,
                'replace_count' => 2,
                'remove_count' => 1,
                'rating_sum' => 9,
                'rating_count' => 2,
                'complaint_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$picker1, $picker2];
    }

    public function test_leaderboard_is_scoped_to_admin_stores_and_sorted(): void
    {
        [$picker1] = $this->seedDailyStats();

        [, $bearer] = $this->createStaffWithToken(roles: ['admin'], shopCodes: ['shop-a'], name: 'Админ Магазинов');

        $response = $this->getJson('/api/v1/analytics/employees?period=week', ['Authorization' => "Bearer {$bearer}"]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $leaderboard = $response->json('data.leaderboard');

        // Только shop-a (строка picker2 в shop-b отрезана scope-ом админа)
        $this->assertCount(2, $leaderboard);
        $this->assertSame((string) $picker1, $leaderboard[0]['employee_id']);
        $this->assertSame(10, $leaderboard[0]['orders_count']);
        $this->assertSame('Первый Сборщиков', $leaderboard[0]['name']);
        $this->assertSame(0.8, $leaderboard[0]['sla_rate']);
        $this->assertSame(4.5, $leaderboard[0]['rating_avg']);
    }

    public function test_admin_cannot_request_foreign_store(): void
    {
        $this->seedDailyStats();

        [, $bearer] = $this->createStaffWithToken(roles: ['admin'], shopCodes: ['shop-a'], name: 'Админ Магазинов');

        $this->getJson('/api/v1/analytics/employees?store_id=shop-b', ['Authorization' => "Bearer {$bearer}"])
            ->assertStatus(403);
    }

    public function test_unbound_admin_sees_all_stores(): void
    {
        $this->seedDailyStats();

        [, $bearer] = $this->createStaffWithToken(roles: ['superadmin'], shopCodes: [], name: 'Супер Админов');

        $response = $this->getJson('/api/v1/analytics/employees', ['Authorization' => "Bearer {$bearer}"]);

        $leaderboard = collect($response->json('data.leaderboard'));

        // Все три сборщика из обоих магазинов
        $this->assertCount(3, $leaderboard);
        $this->assertSame(20, $leaderboard->pluck('orders_count')->sum());
    }

    public function test_store_summary_returns_sla_bottleneck_load_and_dynamics(): void
    {
        $this->seedDailyStats();

        // Журнал для bottleneck: сборка 10 минут, ожидание курьера 20, дорога 25
        $now = CarbonImmutable::now();
        $insert = fn (string $type, CarbonImmutable $at, array $attrs, array $payload = []) => DB::table('analytics_events')->insert(array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'occurred_at' => $at->toIso8601String(),
            'source' => 'delivery',
            'store_id' => 'shop-a',
            'payload' => json_encode($payload),
        ], $attrs));

        $insert('picking.started', $now->subMinutes(120), ['order_id' => 'O1', 'employee_id' => '3']);
        $insert('picking.finished', $now->subMinutes(110), ['order_id' => 'O1', 'employee_id' => '3']);
        $insert('delivery.started', $now->subMinutes(90), ['order_id' => 'O1', 'employee_id' => '9'], ['delivery_id' => 7]);
        $insert('delivery.finished', $now->subMinutes(65), ['order_id' => 'O1', 'employee_id' => '9'], ['delivery_id' => 7]);

        [, $bearer] = $this->createStaffWithToken(roles: ['admin'], shopCodes: ['shop-a'], name: 'Админ Магазинов');

        $response = $this->getJson('/api/v1/analytics/store/summary?period=week', ['Authorization' => "Bearer {$bearer}"]);

        $response->assertStatus(200)
            ->assertJsonPath('data.sla.picking_rate', 0.75)
            ->assertJsonPath('data.bottleneck.avg_picking_sec', 600)
            ->assertJsonPath('data.bottleneck.avg_wait_courier_sec', 1200)
            ->assertJsonPath('data.bottleneck.avg_delivery_sec', 1500)
            ->assertJsonPath('data.totals.orders_count', 16)
            ->assertJsonPath('data.totals.gmv_amount', 1600);

        $this->assertNotEmpty($response->json('data.load_by_hour'));
        $this->assertNotEmpty($response->json('data.dynamics'));
        $this->assertSame(2, $response->json('data.totals.active_employees'));
    }
}
