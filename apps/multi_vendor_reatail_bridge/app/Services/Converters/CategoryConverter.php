<?php

namespace App\Services\Converters;

use Illuminate\Support\Facades\Log;

class CategoryConverter extends BaseConverter
{
    public function __construct(array $config = null)
    {
        $this->retailcrmSource = $config['source'] ?? config('retailcrm.source', 'retailcrm');
    }

    public function fromRetailCRM(array $data, $metadata = null): array
    {
        $updatedAt = $data['updatedAt'] ?? null;
        if (is_array($updatedAt) && isset($updatedAt['date'])) {
            $updatedAt = $updatedAt['date'];
        }

        // Если даты обновления нет, используем текущее время как момент получения актуального состояния
        if (!$updatedAt) {
            $updatedAt = now()->format('Y-m-d H:i:s');
        }

        // Determine source and ID from metadata if available
        // Без metadata используем externalId (id из Bagisto) если есть, иначе retailCrmId
        $id = (string) ($data['externalId'] ?? $data['id']);
        $source = $this->retailcrmSource;

        if ($metadata) {
            $source = $metadata->source;
            $id = $metadata->external_id;
        }

        // Ищем metadata родительской категории если есть parentId
        $parentId = null;
        $parentSource = null;
        if (isset($data['parentId'])) {
            $parentRetailId = (string)$data['parentId'];
            $parentMeta = \App\Models\System\EventSyncMetadata::where('entity_type', 'category')
                ->where('entity_id', $parentRetailId)
                ->first();
            
            if ($parentMeta) {
                $parentId = $parentMeta->external_id;
                $parentSource = $parentMeta->source;
            } else {
                $parentId = $parentRetailId;
                $parentSource = $this->retailcrmSource;
            }
        }

        return [
            'id' => $id,
            'shop_code' => $this->extractShopCode($data),
            'source' => $source,
            'title' => $data['name'] ?? '',
            'parent_id' => $parentId,
            'parent_source' => $parentSource,
            'active' => isset($data['active']) && $data['active'],
            'updated_at' => $updatedAt,
        ];
    }

    public function toRetailCRM(array $data): array
    {
        $result = [
            'name' => $data['title'] ?? '',
            'active' => $data['active'] ?? true,
        ];

        // Добавляем externalId для связи
        if (!empty($data['id'])) {
            $result['externalId'] = (string)$data['id'];
        }

        // Родительская категория - будет добавлена через metadata lookup в handler
        // parentId устанавливается там после поиска в event_sync_metadata

        return $result;
    }
}
