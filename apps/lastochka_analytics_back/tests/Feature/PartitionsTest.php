<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PartitionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_partitions_creates_current_and_next_month(): void
    {
        $this->artisan('analytics:ensure-partitions')->assertSuccessful();

        $current = 'analytics_events_' . now()->format('Y_m');
        $next = 'analytics_events_' . now()->addMonthNoOverflow()->startOfMonth()->format('Y_m');

        $this->assertNotNull(DB::selectOne('SELECT to_regclass(?) AS reg', [$current])->reg);
        $this->assertNotNull(DB::selectOne('SELECT to_regclass(?) AS reg', [$next])->reg);

        // Идемпотентность
        $this->artisan('analytics:ensure-partitions')->assertSuccessful();
    }

    public function test_insert_routes_to_monthly_partition_and_default_catches_outliers(): void
    {
        $this->artisan('analytics:ensure-partitions')->assertSuccessful();

        DB::table('analytics_events')->insert([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.created',
            'occurred_at' => now(),
            'source' => 'storefront',
            'payload' => json_encode(['x' => 1]),
        ]);

        // Событие сильно вне диапазона — ловит DEFAULT-партиция, не теряется
        DB::table('analytics_events')->insert([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.created',
            'occurred_at' => now()->subYears(5),
            'source' => 'storefront',
            'payload' => json_encode(['x' => 2]),
        ]);

        $currentPartition = 'analytics_events_' . now()->format('Y_m');
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS cnt FROM {$currentPartition}")->cnt);
        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS cnt FROM analytics_events_default')->cnt);
        $this->assertSame(2, (int) DB::table('analytics_events')->count());
    }

    public function test_stuck_default_rows_are_moved_when_partition_created(): void
    {
        // Партиций ещё нет — строка текущего месяца оседает в DEFAULT
        DB::table('analytics_events')->insert([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.created',
            'occurred_at' => now(),
            'source' => 'storefront',
            'payload' => json_encode([]),
        ]);

        $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS cnt FROM analytics_events_default')->cnt);

        $this->artisan('analytics:ensure-partitions')->assertSuccessful();

        $currentPartition = 'analytics_events_' . now()->format('Y_m');
        $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS cnt FROM analytics_events_default')->cnt);
        $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS cnt FROM {$currentPartition}")->cnt);
    }
}
