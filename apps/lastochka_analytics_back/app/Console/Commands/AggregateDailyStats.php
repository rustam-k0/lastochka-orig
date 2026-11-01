<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DailyStatsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Пересчёт employee_daily_stats (#11596).
 *
 * По расписанию: каждые 15 минут за сегодня+вчера (живой экран),
 * ночью — за несколько дней назад (доехавшие с опозданием события).
 */
final class AggregateDailyStats extends Command
{
    protected $signature = 'analytics:aggregate-daily
        {--date= : Конкретная дата (Y-m-d) в отчётной таймзоне}
        {--days=1 : Сколько дней пересчитать, начиная с сегодня назад}';

    protected $description = 'Пересчитать дневные агрегаты employee_daily_stats из журнала analytics_events';

    public function handle(DailyStatsAggregator $aggregator): int
    {
        $tz = (string) config('analytics.timezone', 'Europe/Moscow');

        $dates = [];

        if ($this->option('date') !== null) {
            $dates[] = CarbonImmutable::parse((string) $this->option('date'), $tz);
        } else {
            $days = max(1, (int) $this->option('days'));
            $today = CarbonImmutable::now($tz);

            for ($i = 0; $i < $days; $i++) {
                $dates[] = $today->subDays($i);
            }
        }

        foreach ($dates as $date) {
            $count = $aggregator->aggregateDate($date);
            $this->info("{$date->toDateString()}: {$count} строк агрегата");
        }

        return self::SUCCESS;
    }
}
