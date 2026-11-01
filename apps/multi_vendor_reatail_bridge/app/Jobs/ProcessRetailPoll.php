<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable as BusQueueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessRetailPoll implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $entity;
    protected $full;

    /**
     * Create a new job instance.
     */
    public function __construct(string $entity, bool $full = false)
    {
        $this->entity = $entity;
        $this->full = $full;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $entity = $this->entity;
        $full = $this->full;

        Log::channel('polling')->info("Job: Starting retail poll for {$entity} (full: " . ($full ? 'true' : 'false') . ")");

        // Используем atomic lock, чтобы не запускать одинаковые поллеры параллельно
        // Lock на 10 минут (600 сек) вместо 30 минут
        $lock = Cache::lock("retail_poll_{$entity}", 600);

        // Пробуем получить лок в течение 5 секунд (на случай гонки)
        try {
            if (!$lock->block(5)) {
                Log::channel('polling')->info("Job: Poll for {$entity} is already running (lock exists). Skipping.");
                return;
            }
        } catch (\Exception $e) {
            // Если драйвер кэша не поддерживает блокировку (например, file на некоторых системах или array), 
            // block может выбросить исключение или просто не сработать как ожидается.
            // Попробуем обычный get
             if (!$lock->get()) {
                Log::channel('polling')->info("Job: Poll for {$entity} is already running. Skipping.");
                return;
            }
        }

        try {
            // Вызываем консольную команду
            $exitCode = Artisan::call('retail:poll', [
                'entity' => $entity,
                '--full' => $full,
            ]);

            Log::channel('polling')->info("Job: Poll for {$entity} finished with exit code: {$exitCode}");

            // Дублирующий вывод команды (Artisan::output) не логируем: структурные
            // info-логи команды уже пишутся в polling-канал, а дубль раздувал лог.

        } catch (\Exception $e) {
            Log::channel('polling')->error("Job: Poll for {$entity} failed: " . $e->getMessage());
            throw $e; // Rethrow to fail the job
        } finally {
            $lock->release();
        }
    }
}
