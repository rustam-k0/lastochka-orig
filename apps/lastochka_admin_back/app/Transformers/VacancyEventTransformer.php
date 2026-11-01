<?php

namespace App\Transformers;

use App\Models\Vacancy;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VacancyEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'title',
    ];

    /**
     * Преобразовать модель Vacancy в событие для RabbitMQ
     *
     * @param Vacancy $vacancy
     */
    public function toEvent(Model $vacancy, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $vacancy->updated_at ?? now(),
            'id' => (string) $vacancy->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $vacancy->title,
            'city' => $vacancy->city,
            'work_format' => $vacancy->work_format,
            'employment_type' => $vacancy->employment_type,
            'work_schedule' => $vacancy->work_schedule,
            'salary' => $vacancy->salary,
            'short_description' => $vacancy->short_description,
            'description' => $vacancy->description,
            'responsibilities' => $vacancy->responsibilities,
            'requirements' => $vacancy->requirements,
            'conditions' => $vacancy->conditions,
            'link_title' => $vacancy->link_title,
            'link_url' => $vacancy->link_url,
            'position' => $vacancy->position !== null ? (int) $vacancy->position : null,
            'is_published' => (bool) $vacancy->is_published,
            'published_at' => $vacancy->published_at?->toIso8601String(),
            'created_at' => $vacancy->created_at?->toIso8601String(),
            'updated_at' => $vacancy->updated_at?->toIso8601String(),
            'deleted_at' => $vacancy->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий).
     * Не используется — админка только отправляет события вакансий.
     */
    public function fromEvent(array $data): array
    {
        return [
            'title' => $data['title'] ?? '',
            'short_description' => $data['short_description'] ?? '',
            'description' => $data['description'] ?? '',
            'is_published' => $data['is_published'] ?? false,
            'position' => $data['position'] ?? null,
        ];
    }
}
