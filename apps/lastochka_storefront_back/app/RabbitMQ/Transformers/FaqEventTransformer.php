<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Model;

final class FaqEventTransformer extends BaseEventTransformer
{
    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'question'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $faq, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Faq.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'source' => $data['source'],
            'external_id' => (string) $data['id'],
            'question' => $data['question'],
        ];

        // Conditional set: события без ключа не затирают локальные значения.
        foreach (['answer', 'is_active', 'position'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return $attributes;
    }

    /**
     * Поиск существующего вопроса по external_id и source.
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

        return Faq::withTrashed()
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
