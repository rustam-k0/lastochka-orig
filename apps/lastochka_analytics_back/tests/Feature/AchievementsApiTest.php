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
 * GET /api/v1/analytics/me/achievements (#11602): цели, рекорды, бейджи.
 */
final class AchievementsApiTest extends TestCase
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

    private function insertFinished(string $orderId, CarbonImmutable $at, int $replaced, int $cancelled): void
    {
        DB::table('analytics_events')->insert([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'picking.finished',
            'occurred_at' => $at->toIso8601String(),
            'source' => 'delivery',
            'store_id' => 'shop-a',
            'session_id' => 'shift-'.$this->shiftId,
            'employee_id' => (string) $this->userId,
            'order_id' => $orderId,
            'payload' => json_encode([
                'assembled_items' => 3,
                'replaced_items' => $replaced,
                'cancelled_items' => $cancelled,
                'assembled_amount' => 300.0,
            ]),
        ]);
    }

    public function test_achievements_return_goal_records_and_badges(): void
    {
        $now = CarbonImmutable::now();

        // 3 заказа без ошибок за смену (для goal.current и streak)
        foreach (['O1', 'O2', 'O3'] as $i => $orderId) {
            $this->insertFinished($orderId, $now->subMinutes(30 - $i * 5), 0, 0);
        }

        // Лучший день в истории — из агрегатов
        DB::table('employee_daily_stats')->insert([
            'employee_id' => (string) $this->userId,
            'role' => 'picker',
            'store_id' => 'shop-a',
            'stat_date' => $now->subDays(10)->toDateString(),
            'orders_count' => 9,
            'items_count' => 90,
            'gmv_amount' => 9000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/analytics/me/achievements', ['Authorization' => "Bearer {$this->bearer}"]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'picker')
            ->assertJsonPath('data.goal.current', 3)
            ->assertJsonPath('data.goal.target', (int) config('analytics.goals.shift'))
            ->assertJsonPath('data.records.best_day_orders', 9);

        $badges = collect($response->json('data.badges'));
        $codes = $badges->pluck('code');

        $this->assertTrue($codes->contains('sprinter'));
        $this->assertTrue($codes->contains('no_errors_streak'));
        $this->assertTrue($codes->contains('full_qr_shift'));
        $this->assertTrue($codes->contains('top_of_day'));
        $this->assertTrue($codes->contains('no_complaint_days'));

        // 3 заказа подряд без замен/отмен: streak = 3 из 10
        $noErrors = $badges->firstWhere('code', 'no_errors_streak');
        $this->assertSame(3, $noErrors['params']['current']);
        $this->assertFalse($noErrors['earned']);
    }

    public function test_replaced_order_breaks_no_errors_streak(): void
    {
        $now = CarbonImmutable::now();

        // Свежий заказ без ошибок, но предыдущий — с заменой
        $this->insertFinished('O1', $now->subMinutes(40), 1, 0);
        $this->insertFinished('O2', $now->subMinutes(20), 0, 0);

        $response = $this->getJson('/api/v1/analytics/me/achievements', ['Authorization' => "Bearer {$this->bearer}"]);

        $noErrors = collect($response->json('data.badges'))->firstWhere('code', 'no_errors_streak');

        $this->assertSame(1, $noErrors['params']['current']);
    }

    public function test_courier_gets_courier_badges(): void
    {
        [, $bearer] = $this->createStaffWithToken(roles: ['courier'], shopCodes: ['shop-a'], name: 'Курьер Тестов');

        $response = $this->getJson('/api/v1/analytics/me/achievements', ['Authorization' => "Bearer {$bearer}"]);

        $codes = collect($response->json('data.badges'))->pluck('code');

        $this->assertTrue($codes->contains('fast_courier'));
        $this->assertTrue($codes->contains('five_star_streak'));
        $this->assertFalse($codes->contains('no_errors_streak'));
    }
}
