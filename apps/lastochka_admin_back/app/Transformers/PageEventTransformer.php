<?php

namespace App\Transformers;

use App\Models\Page;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PageEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'slug',
        'title',
    ];

    /**
     * Преобразовать модель Page в событие для RabbitMQ.
     * Флаг is_system наружу не уходит: витрине он не нужен.
     *
     * @param Page $page
     */
    public function toEvent(Model $page, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $page->updated_at ?? now(),
            'id' => (string) $page->id,
            'source' => config('rabbitmq-events.source'),
            'slug' => $page->slug,
            'title' => $page->title,
            'content' => $page->content,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'position' => $page->position !== null ? (int) $page->position : null,
            'is_published' => (bool) $page->is_published,
            'published_at' => $page->published_at?->toIso8601String(),
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
            'deleted_at' => $page->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий).
     * Не используется — админка только отправляет события страниц.
     */
    public function fromEvent(array $data): array
    {
        return [
            'slug' => $data['slug'] ?? '',
            'title' => $data['title'] ?? '',
            'content' => $data['content'] ?? '',
            'is_published' => $data['is_published'] ?? false,
            'position' => $data['position'] ?? null,
        ];
    }
}
