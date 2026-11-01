<?php

namespace App\GraphQL\Mutations\Category1cMutations;

use App\Jobs\Category1c\SyncCategoryMappingsJob;
use App\Models\Category1cSyncBatch;

/**
 * Запустить синхронизацию Category1c для магазина.
 *
 * Идемпотентность: если уже есть PENDING/IN_PROGRESS batch для магазина,
 * возвращается существующий (не создаём дубль на повторный клик).
 */
class StartSync
{
    public function __invoke($_, array $args): Category1cSyncBatch
    {
        $shopId = (int) $args['shop_id'];

        $existing = Category1cSyncBatch::query()
            ->where('shop_id', $shopId)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->latest('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $batch = Category1cSyncBatch::create([
            'shop_id' => $shopId,
            'status' => 'PENDING',
            'progress_total' => 0,
            'progress_done' => 0,
            'started_at' => now(),
        ]);

        SyncCategoryMappingsJob::dispatch($batch->id, $shopId);

        return $batch;
    }
}
