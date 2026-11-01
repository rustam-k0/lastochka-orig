<?php

namespace App\Jobs\Category1c;

use App\Models\Category1c;
use App\Models\Category1cSyncBatch;
use App\Models\Product;
use App\Services\Category1c\ProductShowcaseLinker;
use App\Services\ModuleConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Async-job массового применения showcase-маппинга к Product'ам магазина.
 *
 * Алгоритм:
 *  1. Берёт батч (Category1cSyncBatch) и эксклюзивный лок на shop_id
 *     (чтобы две параллельные синхронизации не дрались за одни Product'ы).
 *  2. Считает Product'ы магазина, где привязанная Category1c имеет хотя бы
 *     одну связанную витринную категорию (через pivot category_1c_category).
 *  3. Идёт `lazyById(500)` по этим Product'ам и синхронизирует M:N pivot
 *     `category_product`: список витрин берётся из category1c.showcaseCategories().
 *     Update'ит только реально менявшиеся записи (idempotency).
 *     На каждый изменённый Product диспатчит `ProductUpdated`
 *     (триггерит outbox→RabbitMQ→storefront).
 *  4. Обновляет `last_synced_at` у всех linked Category1c магазина.
 *  5. Обновляет batch'у статус COMPLETED + metadata.touched_products.
 *
 * Failure-mode:
 *  - `tries=1`: автоматических retry нет, пользователь жмёт «Применить» снова.
 *  - При исключении handle() сам ставит batch в FAILED и release'ит лок;
 *    `failed()` дублирует запись на случай если worker упал до finally.
 *
 * См. spec §7.1, §7.2.
 */
class SyncCategoryMappingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public int $batchId,
        public int $shopId,
    ) {
    }

    public function handle(): void
    {
        $batch = Category1cSyncBatch::findOrFail($this->batchId);
        // Стор блокировок отделён от основного кэша: в файловом кэше каталог,
        // созданный CI-раннером, недоступен www-data и Cache::lock падает
        // с «Unable to create lockable file» (см. config cache.lock_store).
        $lock = Cache::store(config('cache.lock_store'))
            ->lock("category_1c_sync:{$this->shopId}", 1800);

        if (!$lock->get()) {
            $batch->update([
                'status' => 'FAILED',
                'error' => 'Another sync is in progress',
                'completed_at' => now(),
            ]);
            return;
        }

        try {
            $batch->update(['status' => 'IN_PROGRESS']);

            $networkMode = ModuleConfigService::getCategory1cNetworkAnchorShopId() !== null;

            $eligible = fn ($q) => $q
                ->where('shop_id', $this->shopId)
                ->whereHas('showcaseCategories');

            // Сетевой режим: товары всех магазинов; категории — якорного ($this->shopId).
            $products = fn () => Product::query()
                ->when(!$networkMode, fn ($q) => $q->where('shop_id', $this->shopId))
                ->whereHas('category1c', $eligible);

            $total = $products()->count();
            $batch->update(['progress_total' => $total]);

            $touchedCount = 0;
            $linker = app(ProductShowcaseLinker::class);
            $products()
                ->with(['category1c.showcaseCategories:id', 'categories:id'])
                ->lazyById(500)
                ->each(function (Product $product) use ($batch, $linker, &$touchedCount) {
                    // Единая per-product логика связки product → витрина (idempotent,
                    // диспатчит ProductUpdated при изменении). См. ProductShowcaseLinker.
                    if ($linker->sync($product)) {
                        $touchedCount++;
                    }

                    $batch->increment('progress_done');
                });

            // Обновляем last_synced_at у всех Category1c магазина, имеющих хотя бы одну
            // привязанную витринную (через pivot).
            $linkedIds = Category1c::query()
                ->where('shop_id', $this->shopId)
                ->whereHas('showcaseCategories')
                ->pluck('id');

            if ($linkedIds->isNotEmpty()) {
                Category1c::whereIn('id', $linkedIds)->update(['last_synced_at' => now()]);
            }

            $batch->update([
                'status' => 'COMPLETED',
                'completed_at' => now(),
                'metadata' => ['touched_products' => $touchedCount],
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $e): void
    {
        Category1cSyncBatch::where('id', $this->batchId)->update([
            'status' => 'FAILED',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
