<?php

namespace App\Services\Idempotency;

use App\Models\System\EventSyncMetadata;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function handle(string $entityType, string $source, string $externalId, ?string $shopCode, string $eventId, int $timestamp, callable $callback): bool
    {
        return DB::transaction(function () use ($entityType, $source, $externalId, $shopCode, $eventId, $timestamp, $callback) {
            $query = EventSyncMetadata::where([
                'entity_type' => $entityType,
                'source' => $source,
                'external_id' => $externalId,
                'shop_code' => $shopCode,
            ]);

            $exists = $query->lockForUpdate()->first();

            if (!$exists) {
                EventSyncMetadata::create([
                    'entity_type' => $entityType,
                    'source' => $source,
                    'external_id' => $externalId,
                    'shop_code' => $shopCode,
                ]);
                $exists = $query->lockForUpdate()->first();
            }

            if ($exists && $exists->last_event_id && $exists->last_event_id === $eventId) {
                return true;
            }

            if ($exists && $exists->last_event_timestamp && $exists->last_event_timestamp->getTimestamp() > $timestamp) {
                return true;
            }

            $result = (bool) $callback();

            if ($result) {
                $exists->last_event_id = $eventId;
                $exists->last_event_timestamp = now()->setTimestamp($timestamp);
                $exists->save();
            }

            return $result;
        });
    }
}
