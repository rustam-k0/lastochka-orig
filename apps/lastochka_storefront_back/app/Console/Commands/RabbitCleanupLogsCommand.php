<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\RabbitMQ\Debug\RabbitMessageLog;
use Carbon\Carbon;
use Illuminate\Console\Command;

final class RabbitCleanupLogsCommand extends Command
{
    protected $signature = 'rabbit:cleanup-logs {--days= : Number of days to retain logs}';

    protected $description = 'Clean up old RabbitMQ debug logs';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('rabbit-debug.retention_days', 7));

        $before = Carbon::now()->subDays($days);

        $this->info("Deleting logs older than {$days} days (before {$before->toDateTimeString()})...");

        $deleted = RabbitMessageLog::where('created_at', '<', $before)->delete();

        $this->info("Deleted {$deleted} log entries.");

        return self::SUCCESS;
    }
}
