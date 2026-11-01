<?php

namespace App\Observers;

use App\Models\System\EventOutbox;
use App\Models\System\EventSyncMetadata;
use Illuminate\Support\Facades\Log;

/**
 * Observer для EventOutbox
 * 
 * Создает метаданные EventSyncMetadata для событий импортированных из RetailCRM
 * чтобы отслеживать связь между ID в RetailCRM и локальными ID
 */
class EventOutboxObserver
{
    /**
     * Обработка события создания записи в Outbox
     *
     * @param EventOutbox $outbox
     * @return void
     */
    public function created(EventOutbox $outbox): void
    {
        // Получаем payload
        $payload = $outbox->payload;

        // Проверяем что это событие из RetailCRM
        $retailcrmSource = config('retailcrm.source', 'retailcrm');
        if (!isset($payload['source']) || $payload['source'] !== $retailcrmSource) {
            return; // Не из RetailCRM - пропускаем
        }

        // Проверяем наличие обязательных полей
        if (!isset($payload['id']) || !isset($outbox->entity_type)) {
            Log::warning('EventOutboxObserver: Missing required fields', [
                'outbox_id' => $outbox->id,
                'entity_type' => $outbox->entity_type,
                'has_id' => isset($payload['id']),
            ]);
            return;
        }

        try {
            // 1. Проверяем, существуют ли уже метаданные для этого source (retailcrm)
            $exists = EventSyncMetadata::where([
                'entity_type' => $outbox->entity_type,
                'source' => $retailcrmSource,
                'external_id' => $payload['id'],
                'shop_code' => $payload['shop_code'] ?? 'default',
            ])->exists();

            if ($exists) {
                Log::debug('EventOutboxObserver: Metadata already exists', [
                    'entity_type' => $outbox->entity_type,
                    'external_id' => $payload['id'],
                    'source' => $retailcrmSource,
                ]);
                return;
            }

            // 2. Проверяем, существуют ли метаданные с таким entity_id (от любого источника)
            // Если entity_id уже привязан к чему-то, нам не нужно создавать дублирующую запись
            $existsByEntityId = EventSyncMetadata::where('entity_type', $outbox->entity_type)
                ->where('entity_id', (string)$payload['id'])
                ->exists();

            if ($existsByEntityId) {
                Log::info('EventOutboxObserver: Metadata already exists for this entity_id (via another source), skipping creation', [
                    'entity_type' => $outbox->entity_type,
                    'entity_id' => $payload['id'],
                ]);
                return;
            }

            // Создаем метаданные
            $metadata = EventSyncMetadata::create([
                'entity_type' => $outbox->entity_type,
                'source' => $retailcrmSource,
                'external_id' => $payload['id'],
                'shop_code' => $payload['shop_code'] ?? 'default',
                'entity_id' => $payload['id'], // ID в RetailCRM = external_id = entity_id
                'last_event_id' => $payload['event_id'] ?? null,
                'last_event_timestamp' => isset($payload['event_timestamp'])
                    ? now()->setTimestamp($payload['event_timestamp'])
                    : now(),
            ]);

            Log::info('✅ EventOutboxObserver: Created metadata for RetailCRM entity', [
                'metadata_id' => $metadata->id,
                'entity_type' => $outbox->entity_type,
                'external_id' => $payload['id'],
                'entity_id' => $payload['id'],
            ]);

        } catch (\Exception $e) {
            Log::error('EventOutboxObserver: Failed to create metadata', [
                'exception' => $e->getMessage(),
                'outbox_id' => $outbox->id,
                'entity_type' => $outbox->entity_type,
                'external_id' => $payload['id'] ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
