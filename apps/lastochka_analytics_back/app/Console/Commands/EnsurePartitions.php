<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Создаёт месячные партиции analytics_events на текущий и следующий месяцы.
 *
 * Запускается после migrate (CI) и ежедневно по schedule. Идемпотентна.
 * События вне созданных диапазонов ловит analytics_events_default; если там
 * успели осесть строки создаваемого диапазона (партиции отставали), они
 * переносятся в новую партицию в той же транзакции — иначе Postgres
 * запрещает CREATE PARTITION при конфликте с данными DEFAULT-партиции.
 */
final class EnsurePartitions extends Command
{
    protected $signature = 'analytics:ensure-partitions {--months=2 : Сколько месяцев вперёд создать (включая текущий)}';

    protected $description = 'Создать месячные партиции analytics_events (текущий + следующие месяцы)';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $start = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $from = $start->addMonths($i)->toDateString();
            $to = $start->addMonths($i + 1)->toDateString();
            $name = 'analytics_events_' . $start->addMonths($i)->format('Y_m');

            if (DB::selectOne('SELECT to_regclass(?) AS reg', [$name])?->reg !== null) {
                $this->line("Партиция {$name} уже существует");
                continue;
            }

            $stuck = (int) DB::selectOne(
                'SELECT count(*) AS cnt FROM analytics_events_default WHERE occurred_at >= ? AND occurred_at < ?',
                [$from, $to],
            )->cnt;

            DB::transaction(function () use ($name, $from, $to, $stuck): void {
                if ($stuck > 0) {
                    // DETACH снимает проверку конфликта с DEFAULT на время создания.
                    DB::unprepared('ALTER TABLE analytics_events DETACH PARTITION analytics_events_default');
                }

                DB::unprepared(sprintf(
                    "CREATE TABLE %s PARTITION OF analytics_events FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $from,
                    $to,
                ));

                if ($stuck > 0) {
                    DB::unprepared(sprintf(
                        "WITH moved AS (
                            DELETE FROM analytics_events_default
                            WHERE occurred_at >= '%s' AND occurred_at < '%s'
                            RETURNING *
                        )
                        INSERT INTO analytics_events OVERRIDING SYSTEM VALUE
                        SELECT * FROM moved
                        ON CONFLICT (event_id, occurred_at) DO NOTHING",
                        $from,
                        $to,
                    ));

                    DB::unprepared('ALTER TABLE analytics_events ATTACH PARTITION analytics_events_default DEFAULT');

                    $this->warn("Перенесено {$stuck} строк из DEFAULT-партиции в {$name}");
                }
            });

            $this->info("Партиция {$name} готова ({$from} → {$to})");
        }

        return self::SUCCESS;
    }
}
