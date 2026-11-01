<?php

declare(strict_types=1);

namespace App\Services\Exchange1C;

use App\Models\CatalogImportChunk;
use App\Models\ExchangeLog;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Шаг deactivate от 1С: мягкое удаление товаров, не попавших в полную выгрузку.
 *
 * 1С присылает команду deactivate после того, как выгрузила все файлы каталога.
 * Состав каждого файла импортёр сохранил в catalog_import_chunks. Здесь чанки
 * магазина объединяются, товары магазина вне объединённого состава мягко
 * удаляются (SoftDeletes → outbox product.deleted → admin_back/storefront), а
 * чанки потребляются.
 *
 * Окно текущей выгрузки задано id логов: файлы каталога с id между предыдущим
 * логом deactivate и текущим. Auto-increment даёт порядок без часов 1С: всё,
 * что 1С прислала до команды deactivate, получило id меньше её лога.
 *
 * Предохранители, каждый отменяет удаление целиком:
 *  - файл окна ещё в обработке → WAITING, job вернётся позже;
 *  - файл окна упал или у него нет чанка → ABORTED (состав неполный);
 *  - нет ни одного чанка → ABORTED;
 *  - доля пропавших выше exchange1c.deactivate_missing_max_share → ABORTED
 *    (усечённая выгрузка на стороне 1С).
 * Ошибка в одном окне самолечится следующей выгрузкой: 1С каждый раз шлёт
 * каталог целиком, и окно начинается заново от этого лога deactivate.
 *
 * Чанки прерванной прошлой выгрузки (deactivate не пришёл) объединяются с
 * текущей: надмножество никогда не удалит лишнего, только отложит удаление.
 */
class MissingProductsDeactivator
{
    public const STATUS_DONE = 'done';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_ABORTED = 'aborted';

    /** Размер пачки при удалении, чтобы не держать одну огромную транзакцию. */
    protected const DELETE_CHUNK_SIZE = 500;

    public function run(ExchangeLog $log, int $shopId): MissingProductsDeactivationResult
    {
        $windowLogs = $this->windowLogs($log, $shopId);

        if ($windowLogs->contains('status', ExchangeLog::STATUS_STARTED)) {
            return $this->waiting($log, $windowLogs);
        }

        $chunks = CatalogImportChunk::query()
            ->where('shop_id', $shopId)
            ->where('exchange_log_id', '<', $log->id)
            ->get();

        $failed = $windowLogs->where('status', '!=', ExchangeLog::STATUS_SUCCESS);
        if ($failed->isNotEmpty()) {
            return $this->abort($log, $chunks, sprintf(
                'Файлы каталога окна выгрузки завершились с ошибкой (логи %s), состав выгрузки неполный',
                $failed->pluck('id')->implode(', ')
            ));
        }

        $withoutChunk = $windowLogs->pluck('id')->diff($chunks->pluck('exchange_log_id'));
        if ($withoutChunk->isNotEmpty()) {
            return $this->abort($log, $chunks, sprintf(
                'У файлов каталога окна выгрузки нет записи о составе (логи %s): фича включена посреди выгрузки?',
                $withoutChunk->implode(', ')
            ));
        }

        if ($chunks->isEmpty()) {
            return $this->abort($log, $chunks, 'Нет ни одного файла каталога с записью о составе выгрузки');
        }

        $seen = [];
        foreach ($chunks as $chunk) {
            foreach ($chunk->external_ids ?? [] as $externalId) {
                $seen[$externalId] = true;
            }
        }

        $alive = Product::query()
            ->where('shop_id', $shopId)
            ->pluck('external_id', 'id');

        $missingIds = $alive
            ->reject(fn (string $externalId) => isset($seen[$externalId]))
            ->keys();

        $maxShare = (float) config('exchange1c.deactivate_missing_max_share', 0.2);
        $share = $alive->isEmpty() ? 0.0 : $missingIds->count() / $alive->count();

        if ($share > $maxShare) {
            return $this->abort($log, $chunks, sprintf(
                'В полную выгрузку не попало %d из %d товаров (%.1f%%), порог %.1f%%: похоже на усечённую выгрузку, удаление отменено',
                $missingIds->count(),
                $alive->count(),
                $share * 100,
                $maxShare * 100
            ), $alive->count(), count($seen), $missingIds->count());
        }

        $deleted = $this->deleteProducts($shopId, $missingIds);
        $this->consume($chunks);

        $message = sprintf(
            'Полная выгрузка: в составе %d Ид, в базе %d товаров, не попало в выгрузку %d, удалено %d',
            count($seen),
            $alive->count(),
            $missingIds->count(),
            $deleted
        );

        $log->success($deleted, $message);

        Log::channel('1c-exchange')->info('Шаг deactivate завершён', [
            'shop_id' => $shopId,
            'log_id' => $log->id,
            'seen' => count($seen),
            'alive' => $alive->count(),
            'missing' => $missingIds->count(),
            'deleted' => $deleted,
        ]);

        return new MissingProductsDeactivationResult(
            self::STATUS_DONE,
            $message,
            $alive->count(),
            count($seen),
            $missingIds->count(),
            $deleted
        );
    }

    /**
     * Логи файлов каталога текущего окна выгрузки.
     *
     * @return Collection<int, ExchangeLog>
     */
    protected function windowLogs(ExchangeLog $log, int $shopId): Collection
    {
        $previousDeactivateId = (int) ExchangeLog::query()
            ->where('type', ExchangeLog::TYPE_DEACTIVATE)
            ->where('shop_id', $shopId)
            ->where('id', '<', $log->id)
            ->max('id');

        return ExchangeLog::query()
            ->where('type', ExchangeLog::TYPE_CATALOG_IMPORT)
            ->where('shop_id', $shopId)
            ->where('id', '>', $previousDeactivateId)
            ->where('id', '<', $log->id)
            ->get(['id', 'status']);
    }

    protected function waiting(ExchangeLog $log, Collection $windowLogs): MissingProductsDeactivationResult
    {
        $pending = $windowLogs->where('status', ExchangeLog::STATUS_STARTED)->pluck('id');

        Log::channel('1c-exchange')->info('Шаг deactivate ждёт обработки файлов каталога', [
            'log_id' => $log->id,
            'pending_logs' => $pending->all(),
        ]);

        return new MissingProductsDeactivationResult(
            self::STATUS_WAITING,
            'Файлы каталога ещё в обработке: логи ' . $pending->implode(', ')
        );
    }

    protected function abort(
        ExchangeLog $log,
        Collection $chunks,
        string $message,
        int $alive = 0,
        int $seen = 0,
        int $missing = 0
    ): MissingProductsDeactivationResult {
        $this->consume($chunks);
        $log->error($message);

        Log::channel('1c-exchange')->warning('Шаг deactivate отменён: ' . $message, [
            'shop_id' => $log->shop_id,
            'log_id' => $log->id,
        ]);

        return new MissingProductsDeactivationResult(self::STATUS_ABORTED, $message, $alive, $seen, $missing);
    }

    /**
     * Мягкое удаление по одному: нужны события модели, чтобы observer outbox
     * положил product.deleted по каждому товару, как при DelProd.
     */
    protected function deleteProducts(int $shopId, Collection $productIds): int
    {
        $deleted = 0;

        foreach ($productIds->chunk(self::DELETE_CHUNK_SIZE) as $ids) {
            $products = Product::query()
                ->where('shop_id', $shopId)
                ->whereIn('id', $ids->all())
                ->get();

            foreach ($products as $product) {
                $product->delete();
                $deleted++;

                Log::channel('1c-exchange')->info('Товар удалён: не попал в полную выгрузку 1С', [
                    'external_id' => $product->external_id,
                    'product_id' => $product->id,
                    'shop_id' => $shopId,
                    'sku' => $product->sku,
                    'code' => $product->code,
                    'name' => $product->name,
                ]);
            }
        }

        return $deleted;
    }

    /**
     * Потребить чанки: удаляем ровно те строки, что были загружены, чтобы не
     * задеть чанки следующей выгрузки, пришедшие во время работы.
     */
    protected function consume(Collection $chunks): void
    {
        if ($chunks->isEmpty()) {
            return;
        }

        CatalogImportChunk::query()->whereIn('id', $chunks->pluck('id')->all())->delete();
    }
}
