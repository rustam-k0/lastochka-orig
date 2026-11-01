<?php

namespace App\RabbitMQ\Transformers;

use App\Models\Category;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Category Event Transformer
 *
 * Трансформирует модель Category в формат событий RabbitMQ
 * согласно спецификации docs/exchange.md
 */
class CategoryEventTransformer implements EventTransformer
{
    /**
     * Transform Category model to event payload
     *
     * @param Model|Category $category
     * @param Model|null $relatedModel
     * @param string|null $eventId
     * @return array
     */
    public function toEvent(Model $category, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $source = config('rabbitmq-events.source', '1c');

        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => ($category->updated_at ?? now())->toIso8601String(),
            'id' => $category->external_id,
            'shop_code' => $this->getCurrentShopCode(),
            'source' => $source,
            'parent_id' => $category->parent?->external_id,
            'parent_source' => $category->parent ? $source : null,
            'title' => $category->name,
            'image_url' => null,
            'active' => $category->is_active,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
            'deleted_at' => null,
        ];
    }

    /**
     * Transform event payload to Category model attributes
     *
     * @param array $data
     * @return array
     */
    public function fromEvent(array $data): array
    {
        return [
            'external_id' => $data['id'],
            'name' => $data['title'],
            'is_active' => $data['active'] ?? true,
        ];
    }

    /**
     * Получить текущий shop_code из контейнера или конфига
     */
    protected function getCurrentShopCode(): string
    {
        try {
            return app('current_shop_code');
        } catch (\Throwable $e) {
            return config('rabbitmq-events.shop_code', 'aladdin');
        }
    }

    /**
     * Validate event payload structure
     *
     * @param array $data
     * @return bool
     */
    public function validate(array $data): bool
    {
        $required = ['event_id', 'id', 'shop_code', 'source', 'title'];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        return true;
    }
}
