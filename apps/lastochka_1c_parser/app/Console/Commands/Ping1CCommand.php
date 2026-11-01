<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Проверка связи с 1С
 *
 * php artisan 1c:ping
 */
class Ping1CCommand extends Command
{
    protected $signature = '1c:ping
                            {--url= : URL сервера 1С (по умолчанию из .env)}';

    protected $description = 'Проверка связи с сервером 1С';

    public function handle(): int
    {
        $url = $this->option('url') ?? config('exchange1c.server_url', 'http://192.168.70.254/tp7');

        $this->info("Проверка связи с 1С: {$url}");

        try {
            $response = Http::timeout(10)
                ->withoutVerifying()
                ->get($url);

            if ($response->successful() || $response->status() === 401) {
                $this->info('✓ Сервер 1С доступен');
                $this->line("  HTTP статус: {$response->status()}");
                return self::SUCCESS;
            }

            $this->warn("⚠ Сервер ответил с кодом: {$response->status()}");
            return self::FAILURE;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('✗ Не удалось подключиться к серверу 1С');
            $this->line("  Ошибка: {$e->getMessage()}");
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("✗ Ошибка: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
