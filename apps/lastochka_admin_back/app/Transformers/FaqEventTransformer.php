<?php

namespace App\Transformers;

use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FaqEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'question',
    ];

    /**
     * Преобразовать модель Faq в событие для RabbitMQ
     *
     * @param \App\Models\Faq $faq
     */
    public function toEvent(Model $faq, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $faq->updated_at ?? now(),
            'id' => (string) $faq->id,
            'source' => config('rabbitmq-events.source'),
            'question' => $faq->question,
            'answer' => $faq->answer,
            'is_active' => (bool) $faq->is_active,
            'position' => $faq->position !== null ? (int) $faq->position : null,
            'created_at' => $faq->created_at?->toIso8601String(),
            'updated_at' => $faq->updated_at?->toIso8601String(),
            'deleted_at' => $faq->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется, так как мы только отправляем события
     */
    public function fromEvent(array $data): array
    {
        return [
            'question' => $data['question'] ?? '',
            'answer' => $data['answer'] ?? '',
            'is_active' => $data['is_active'] ?? true,
            'position' => $data['position'] ?? null,
        ];
    }
}
