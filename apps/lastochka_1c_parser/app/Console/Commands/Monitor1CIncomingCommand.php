<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Monitor1CIncomingCommand extends Command
{
    protected $signature = 'monitor:1c-incoming {--sale : Показывать только sale-запросы}';

    protected $description = 'Мониторинг входящих данных от 1С в реальном времени';

    public function handle(): int
    {
        $logPath = storage_path('logs/1c-exchange-' . date('Y-m-d') . '.log');

        if (!file_exists($logPath)) {
            $this->error("Лог-файл не найден: {$logPath}");
            $this->info("Ожидаем создания файла...");
        }

        $this->info("📡 Мониторинг входящих данных от 1С");
        $this->info("Лог: {$logPath}");
        $this->info("Для выхода нажмите Ctrl+C");
        $this->newLine();

        $filter = $this->option('sale') ? '[SALE]' : null;

        // tail -f эквивалент
        $handle = null;
        $lastSize = 0;

        while (true) {
            if (!file_exists($logPath)) {
                sleep(1);
                continue;
            }

            $currentSize = filesize($logPath);

            if ($handle === null) {
                $handle = fopen($logPath, 'r');
                fseek($handle, 0, SEEK_END);
                $lastSize = ftell($handle);
            }

            clearstatcache(true, $logPath);
            $currentSize = filesize($logPath);

            if ($currentSize > $lastSize) {
                fseek($handle, $lastSize);
                while (($line = fgets($handle)) !== false) {
                    if ($filter && !str_contains($line, $filter)) {
                        continue;
                    }

                    // Подсветка важных частей
                    if (str_contains($line, '[SALE]')) {
                        $this->warn($line);
                    } elseif (str_contains($line, 'Статус')) {
                        $this->info($line);
                    } else {
                        $this->line($line);
                    }
                }
                $lastSize = ftell($handle);
            }

            usleep(100000); // 100ms
        }

        return 0;
    }
}
