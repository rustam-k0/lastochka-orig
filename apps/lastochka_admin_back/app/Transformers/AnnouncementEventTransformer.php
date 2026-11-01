<?php

namespace App\Transformers;

use App\Models\Announcement;
use App\Models\MerchantPromocode;
use App\Models\Status;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ResolvesExternalReferences;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnnouncementEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;
    use ResolvesExternalReferences;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
        'title',
        'short_description',
        'description',
    ];

    /**
     * Внешние ссылки для разрешения (при входящих событиях)
     */
    protected array $externalReferences = [
        'promocode_id' => [
            'type' => MerchantPromocode::class,
            'source_field' => 'promocode_source',
            'id_field' => 'promocode_id',
            'nullable' => true,
        ],
    ];

    /**
     * Преобразовать модель Announcement в событие для RabbitMQ
     *
     * @param Announcement $announcement
     */
    public function toEvent(Model $announcement, ?object $relatedModel = null, ?string $eventId = null): array
    {
        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $announcement->updated_at ?? now(),
            'id' => (string) $announcement->id,
            'source' => config('rabbitmq-events.source'),
            'title' => $announcement->title,
            'short_description' => $announcement->short_description,
            'description' => $announcement->description,
            'is_active' => (bool) $announcement->is_active,
            'image_url' => $this->getImageUrl($announcement),
            'created_at' => $announcement->created_at?->toIso8601String(),
            'updated_at' => $announcement->updated_at?->toIso8601String(),
            'deleted_at' => $announcement->deleted_at?->toIso8601String(),
        ];

        // Добавить promocode_id + promocode_source через external reference
        if ($announcement->promocode_id) {
            $promocodeMeta = EventSyncMetadata::where([
                'entity_type' => 'merchant_promocodes',
                'entity_id' => $announcement->promocode_id,
            ])->first();

            if ($promocodeMeta) {
                $eventData['promocode_id'] = $promocodeMeta->external_id;
                $eventData['promocode_source'] = $promocodeMeta->source;
            } else {
                $eventData['promocode_id'] = (string) $announcement->promocode_id;
                $eventData['promocode_source'] = config('rabbitmq-events.source');
            }
        }

        return $eventData;
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется, так как мы только отправляем события
     */
    public function fromEvent(array $data): array
    {
        return [
            'title' => $data['title'] ?? 'Unknown',
            'short_description' => $data['short_description'] ?? '',
            'description' => $data['description'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /**
     * Получить URL изображения
     */
    protected function getImageUrl(Announcement $announcement): ?string
    {
        if (!$announcement->relationLoaded('image')) {
            $announcement->load('image');
        }

        if ($announcement->image && $announcement->image->path) {
            return PathNormalizer::url($announcement->image->path);
        }

        return null;
    }
}