<?php

namespace App\Jobs;

use App\Jobs\Concerns\ArchivesProcessedFile;
use App\Models\ExchangeLog;
use App\Services\Exchange1C\RestsImporter;
use App\Services\ExchangeDiagnosticsPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для импорта остатков из 1С (файлы rests___*.xml)
 */
class ImportRestsJob implements ShouldQueue
{
    use ArchivesProcessedFile, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток
     */
    public int $tries = 3;

    /**
     * Таймаут в секундах
     */
    public int $timeout = 300;

    /**
     * Задержка между попытками (секунды)
     */
    public int $backoff = 60;

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

        $importer = $this->makeImporter();
        $log = $this->logId ? ExchangeLog::find($this->logId) : null;

        try {
            Log::channel('1c-exchange')->info("ImportRestsJob: начало обработки {$this->filePath}");

            $result = $importer->import($this->filePath, $log);

            Log::channel('1c-exchange')->info("ImportRestsJob: завершено", $result);

            // Race-condition с ImportCatalogJob: часть товаров ещё не создана каталогом.
            // Пока остались попытки — release: штатный повтор без uncaught-исключения, поэтому
            // Sentry/GlitchTip НЕ получают ложную ошибку на каждой рядовой гонке. Уже записанные
            // stocks идемпотентны (Stock::updateOrCreate), на повторе добьём пропущенные, когда
            // catalog завершится. Файл сохраняем для повтора — не чистим.
            $skipped = (int) ($result['skipped'] ?? 0);
            if ($skipped > 0 && $this->attempts() < $this->tries) {
                Log::channel('1c-exchange')->info(
                    "ImportRestsJob: {$skipped} товаров не найдены, повтор "
                    . "(попытка {$this->attempts()}/{$this->tries}) через {$this->backoff}s"
                );
                $this->release($this->backoff);

                return;
            }

            // Попытки исчерпаны, а часть товаров так и не появилась — вероятно они вне
            // whitelist или удалены из номенклатуры 1С (retry бесполезен). НЕ роняем обмен
            // в failed: остальные остатки импортированы, пропущенные догонит следующий обмен.
            if ($skipped > 0) {
                Log::channel('1c-exchange')->warning(
                    "ImportRestsJob: {$skipped} товаров не найдены после {$this->tries} попыток, "
                    . "обмен завершён с пропусками (вероятно вне whitelist/удалены из 1С)"
                );
            }

            if ($log) {
                $log->refresh();
                ExchangeDiagnosticsPublisher::publish($log, $result);
            }

            // Очистка временного файла
            $this->cleanupFile();
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error("ImportRestsJob: ошибка - {$e->getMessage()}");

            $log?->error($e->getMessage());

            throw $e;
        }
    }

    /**
     * Обработка неудачного выполнения
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('1c-exchange')->error("ImportRestsJob: FAILED - {$exception->getMessage()}", [
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
     * Фабрика импортёра (seam для подмены в тестах).
     */
    protected function makeImporter(): RestsImporter
    {
        return new RestsImporter($this->shopId);
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
