<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkFormat;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Model;

final class VacancyEventTransformer extends BaseEventTransformer
{
    /**
     * Поля, которые применяются только при наличии ключа в событии:
     * partial-события не должны затирать локальные значения.
     *
     * @var array<string>
     */
    private const OPTIONAL_FIELDS = [
        'city',
        'work_schedule',
        'salary',
        'short_description',
        'description',
        'responsibilities',
        'requirements',
        'conditions',
        'link_title',
        'link_url',
        'position',
        'is_published',
        'published_at',
    ];

    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'title'];
    }

    /**
     * Не используется — только consume.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $vacancy, ?object $shop = null, ?string $eventId = null): array
    {
        return [];
    }

    /**
     * Преобразование данных события в атрибуты модели Vacancy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'source' => $data['source'],
            'external_id' => (string) $data['id'],
            'title' => $data['title'],
        ];

        foreach (self::OPTIONAL_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        // Неизвестные значения справочников трактуем как «не указано»,
        // иначе cast в enum уронит обработку события.
        if (array_key_exists('work_format', $data)) {
            $attributes['work_format'] = $data['work_format'] !== null
                ? VacancyWorkFormat::tryFrom((string) $data['work_format'])
                : null;
        }

        if (array_key_exists('employment_type', $data)) {
            $attributes['employment_type'] = $data['employment_type'] !== null
                ? VacancyEmploymentType::tryFrom((string) $data['employment_type'])
                : null;
        }

        return $attributes;
    }

    /**
     * Поиск существующей вакансии по external_id и source.
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

        return Vacancy::withTrashed()
            ->where('external_id', $externalId)
            ->where('source', $source)
            ->first();
    }
}
