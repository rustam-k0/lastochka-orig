<?php

namespace App\Services\Converters;

use App\Models\System\EventSyncMetadata;
use App\Services\Converters\Concerns\ResolvesShopCode;

abstract class BaseConverter
{
    use ResolvesShopCode;

    /**
     * Источник событий из RetailCRM
     */
    protected string $retailcrmSource;

    /**
     * Конвертирует данные из формата брокера сообщений в формат RetailCRM.
     *
     * @param array $data
     * @return array
     */
    abstract public function toRetailCRM(array $data): array;

    /**
     * Конвертирует данные из формата RetailCRM в формат брокера сообщений.
     *
     * @param array $data
     * @param \App\Models\System\EventSyncMetadata|null $metadata
     * @return array
     */
    abstract public function fromRetailCRM(array $data, $metadata = null): array;

    /**
     * Ищет метаданные сущности по её ID в RetailCRM (entity_id).
     * Используется для восстановления оригинального source при получении данных из CRM.
     *
     * @param string $entityType Тип сущности (product, customer, order, etc.)
     * @param string $entityId ID сущности в RetailCRM
     * @param string|null $shopCode Код магазина (опционально)
     * @return EventSyncMetadata|null
     */
    protected function findMetadataByEntityId(
        string $entityType,
        string $entityId,
        ?string $shopCode = null
    ): ?EventSyncMetadata {
        $query = EventSyncMetadata::where('entity_type', $entityType)
            ->where('entity_id', $entityId);

        if ($shopCode !== null) {
            $query->where('shop_code', $shopCode);
        }

        return $query->first();
    }

    /**
     * Определяет source и external_id сущности по её ID в RetailCRM.
     * Если метаданные найдены — возвращает оригинальный source и external_id.
     * Если не найдены — возвращает retailcrmSource и entityId как fallback.
     *
     * @param string $entityType Тип сущности
     * @param string $entityId ID в RetailCRM
     * @param string|null $shopCode Код магазина
     * @return array{source: string, external_id: string}
     */
    protected function resolveSourceAndExternalId(
        string $entityType,
        string $entityId,
        ?string $shopCode = null
    ): array {
        $metadata = $this->findMetadataByEntityId($entityType, $entityId, $shopCode);

        if ($metadata) {
            return [
                'source' => $metadata->source,
                'external_id' => $metadata->external_id,
            ];
        }

        // Fallback: если метаданных нет, считаем что сущность из RetailCRM
        return [
            'source' => $this->retailcrmSource,
            'external_id' => $entityId,
        ];
    }
}
