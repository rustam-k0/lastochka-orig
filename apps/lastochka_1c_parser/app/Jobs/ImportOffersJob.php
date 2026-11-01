<?php

namespace App\Jobs;

use App\Jobs\Concerns\ArchivesProcessedFile;
use App\Models\ExchangeLog;
use App\Services\Exchange1C\OffersImporter;
use App\Services\ExchangeDiagnosticsPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для импорта предложений (цены/остатки) из 1С
 */
class ImportOffersJob implements ShouldQueue
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
        public int $offset = 0,
    ) {}

    /**
     * Выполнение задачи
     */
    public function handle(): void
    {
        $this->restoreShopContext();

        $importer = $this->makeImporter();
        $log = $this->logId ? ExchangeLog::find($this->logId) : null;
        $chunkSize = max(1, (int) config('exchange1c.batch_size', 500));

        try {
            Log::channel('1c-exchange')->info(
                "ImportOffersJob: чанк offset={$this->offset}, size={$chunkSize}, файл {$this->filePath}"
            );

            // Обрабатываем окно предложений [offset, offset+chunkSize) короткой транзакцией.
            $result = $importer->importChunk($this->filePath, $this->offset, $chunkSize, $log);

            // Файл не найден (удалён новой сессией обмена) — обрабатывать нечего, цепочку не продолжаем.
            if (($result['status'] ?? null) === 'file_not_found') {
                Log::channel('1c-exchange')->warning('ImportOffersJob: offers-файл не найден, импорт прерван');
                return;
            }

            // Race-condition с ImportCatalogJob: часть товаров в этом чанке ещё не создана.
            // Пока остались попытки — release: тихий повтор ЭТОГО чанка без uncaught-исключения
            // (Sentry/GlitchTip не получают ложную ошибку на рядовой гонке). Следующий чанк НЕ
            // планируем и файл НЕ чистим — они поедут после успешного прохождения текущего.
            // Уже записанные price/stock идемпотентны (updateOrCreate), на повторе добьём пропущенные.
            $skipped = (int) ($result['skipped'] ?? 0);
            if ($skipped > 0 && $this->attempts() < $this->tries) {
                Log::channel('1c-exchange')->info(
                    "ImportOffersJob: {$skipped} товаров не найдены в чанке offset={$this->offset}, повтор "
                    . "(попытка {$this->attempts()}/{$this->tries}) через {$this->backoff}s"
                );
                $this->release($this->backoff);

                return;
            }

            // Попытки исчерпаны, а товары так и не появились — вероятно они вне whitelist или
            // удалены из номенклатуры 1С (retry бесполезен). НЕ роняем обмен в failed и НЕ
            // застреваем на этом чанке: логируем пропуски и продолжаем пагинацию как обычно.
            if ($skipped > 0) {
                Log::channel('1c-exchange')->warning(
                    "ImportOffersJob: {$skipped} товаров не найдены в чанке offset={$this->offset} "
                    . "после {$this->tries} попыток, продолжаем пагинацию (вероятно вне whitelist/удалены из 1С)"
                );
            }

            // Копим счётчик обработанных записей в логе (финальный статус ставит последний чанк).
            $log?->increment('records_count', (int) ($result['prices'] ?? 0) + (int) ($result['stocks'] ?? 0));

            $total = (int) ($result['total'] ?? 0);
            $next = $this->offset + $chunkSize;

            if ($next < $total) {
                // Остались предложения — планомерно ставим следующий чанк в очередь.
                // Файл НЕ удаляем: он нужен следующим чанкам.
                $this->dispatchNextChunk($next);
                Log::channel('1c-exchange')->info("ImportOffersJob: запланирован чанк offset={$next} (total={$total})");

                return;
            }

            // Последний чанк — финализируем лог, публикуем диагностику в 1С, чистим временный файл.
            $log?->update([
                'status' => ExchangeLog::STATUS_SUCCESS,
                'finished_at' => now(),
                'message' => "Импорт предложений завершён: обработано {$total} предложений",
            ]);

            if ($log) {
                $log->refresh();
                ExchangeDiagnosticsPublisher::publish($log, $result);
            }

            $this->cleanupFile();
            Log::channel('1c-exchange')->info("ImportOffersJob: импорт offers завершён (total={$total})");
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error("ImportOffersJob: ошибка - {$e->getMessage()}");

            $log?->error($e->getMessage());

            throw $e;
        }
    }

    /**
     * Обработка неудачного выполнения
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('1c-exchange')->error("ImportOffersJob: FAILED - {$exception->getMessage()}", [
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
    protected function makeImporter(): OffersImporter
    {
        return new OffersImporter($this->shopId);
    }

    /**
     * Планирование следующего чанка (seam для подмены в тестах).
     */
    protected function dispatchNextChunk(int $next): void
    {
        self::dispatch($this->filePath, $this->logId, $this->shopId, $this->shopCode, $next);
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
