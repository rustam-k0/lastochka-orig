<?php

namespace App\Jobs;

use App\Models\ExchangeLog;
use App\Services\Exchange1C\MissingProductsDeactivator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job шага deactivate от 1С: удаление товаров, не попавших в полную выгрузку.
 *
 * Файлы каталога импортируются асинхронно (ImportCatalogJob, numprocs=2), и к
 * моменту команды deactivate последние из них могут быть ещё в очереди. Пока
 * в окне выгрузки есть файл в обработке, job откладывает себя на RELEASE_DELAY
 * секунд; бюджет ожидания ограничен $tries, после чего лог помечается ошибкой,
 * а товары остаются нетронутыми (следующая выгрузка начнёт заново).
 */
class DeactivateMissingProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Пауза между проверками, пока файлы каталога ещё импортируются (секунды). */
    public const RELEASE_DELAY = 20;

    /**
     * Каждый release() съедает попытку: 30 × 20 с = 10 минут ожидания
     * хвоста импорта, дальше failed().
     */
    public int $tries = 30;

    public int $timeout = 600;

    public function __construct(
        public readonly int $logId,
        public readonly int $shopId,
        public readonly ?string $shopCode = null,
        public readonly ?string $startTime = null,
    ) {}

    public function handle(MissingProductsDeactivator $deactivator): void
    {
        $this->restoreShopContext();

        $log = ExchangeLog::find($this->logId);
        if ($log === null) {
            Log::channel('1c-exchange')->warning('DeactivateMissingProductsJob: лог не найден', ['log_id' => $this->logId]);

            return;
        }

        if ($log->status !== ExchangeLog::STATUS_STARTED) {
            // Повторная доставка уже завершённого шага (например, после таймаута
            // воркера): результат есть, второй раз не удаляем.
            return;
        }

        try {
            $result = $deactivator->run($log, $this->shopId);
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error("DeactivateMissingProductsJob: ошибка - {$e->getMessage()}");
            $log->error($e->getMessage());

            throw $e;
        }

        if ($result->status === MissingProductsDeactivator::STATUS_WAITING) {
            $this->release(self::RELEASE_DELAY);

            return;
        }

        Log::channel('1c-exchange')->info('DeactivateMissingProductsJob: завершено', $result->toArray());
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('1c-exchange')->error("DeactivateMissingProductsJob: FAILED - {$exception->getMessage()}", [
            'log_id' => $this->logId,
            'shop_id' => $this->shopId,
        ]);

        $log = ExchangeLog::find($this->logId);
        if ($log && $log->status === ExchangeLog::STATUS_STARTED) {
            $log->error("Job failed: {$exception->getMessage()}");
        }
    }

    protected function restoreShopContext(): void
    {
        app()->instance('current_shop_id', $this->shopId);

        if ($this->shopCode) {
            app()->instance('current_shop_code', $this->shopCode);
        }
    }
}
