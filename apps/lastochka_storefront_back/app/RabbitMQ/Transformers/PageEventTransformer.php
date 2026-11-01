<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Page;
use Illuminate\Database\Eloquent\Model;

final class PageEventTransformer extends BaseEventTransformer
{
    /**
     * Поля, которые применяются только при наличии ключа в событии:
     * partial-события не должны затирать локальные значения.
     *
     * @var array<string>
     */
    private const OPTIONAL_FIELDS = [
        'content',
        'meta_title',
        'meta_description',
        'position',
        'is_published',
        'published_at',
    ];

    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'slug', 'title'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $page, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'source' => $data['source'],
            'external_id' => (string) $data['id'],
            'slug' => $data['slug'],
            'title' => $data['title'],
        ];

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return $attributes;
    }

    /**
     * Поиск существующей страницы по external_id и source.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $externalId = $eventData['id'] ?? null;
        $source = $eventData['source'] ?? null;

        if (! $externalId || ! $source) {
            return null;
        }

        return Page::withTrashed()
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
