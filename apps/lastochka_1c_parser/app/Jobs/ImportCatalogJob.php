<?php

namespace App\Jobs;

use App\Jobs\Concerns\ArchivesProcessedFile;
use App\Models\ExchangeLog;
use App\Services\Exchange1C\CatalogImporter;
use App\Services\Exchange1C\ProductWhitelist;
use App\Services\ExchangeDiagnosticsPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для импорта каталога из 1С
 */
class ImportCatalogJob implements ShouldQueue
{
    use ArchivesProcessedFile, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток
     */
    public int $tries = 3;

    /**
     * Таймаут в секундах
     */
    public int $timeout = 600;

    /**
     * Задержка между попытками (секунды)
     */
    public int $backoff = 300;

    public function __construct(
        protected string $filePath,
        protected ?int $logId = null,
        protected ?int $shopId = null,
        protected ?string $shopCode = null,
    ) {}

    /**
     * Выполнение задачи
     */
    public function handle(): void
    {
        $this->restoreShopContext();

        $whitelist = ProductWhitelist::fromShopConfig();
        $importer = new CatalogImporter($whitelist, $this->shopId);

        $log = $this->logId ? ExchangeLog::find($this->logId) : null;

        try {
            Log::channel('1c-exchange')->info("ImportCatalogJob: начало обработки {$this->filePath}");

            $result = $importer->import($this->filePath, $log);

            Log::channel('1c-exchange')->info("ImportCatalogJob: завершено", $result);

            if ($log) {
                $log->refresh();
                ExchangeDiagnosticsPublisher::publish($log, $result);
            }

            // Очистка временного файла
            $this->cleanupFile();
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error("ImportCatalogJob: ошибка - {$e->getMessage()}");

            $log?->error($e->getMessage());

            throw $e;
        }
    }

    /**
     * Обработка неудачного выполнения
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('1c-exchange')->error("ImportCatalogJob: FAILED - {$exception->getMessage()}", [
            'file' => $this->filePath,
            'trace' => $exception->getTraceAsString(),
        ]);

        if ($this->logId) {
            ExchangeLog::find($this->logId)?->error(
                "Job failed: {$exception->getMessage()}"
            );
        }
    }

    /**
     * Восстановление shop-контекста в worker'е
     */
    protected function restoreShopContext(): void
    {
        if ($this->shopId) {
            app()->instance('current_shop_id', $this->shopId);
        }
        if ($this->shopCode) {
            app()->instance('current_shop_code', $this->shopCode);
        }
    }
}
