<?php

namespace App\Transformers;

use App\Models\IntegrationConfig;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IntegrationConfigEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = ['event_id', 'id', 'source', 'integration_type'];

    /**
     * Преобразовать модель IntegrationConfig в событие для RabbitMQ
     *
     * @param IntegrationConfig $config
     */
    public function toEvent(Model $config, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            // pitfall #9: event_timestamp всегда now(), не updated_at — иначе
            // повторные публикации (retries, touch) теряются консьюмерами.
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $config->id,
            'source' => config('rabbitmq-events.source'),
            'integration_type' => $config->integration_type,
            'driver' => $config->driver,
            'credentials' => $config->credentials[$config->driver] ?? [], // Только активный драйвер
            'is_active' => (bool) $config->is_active,
            'sync_categories_enabled' => (bool) $config->sync_categories_enabled,
            'sync_images_enabled' => (bool) $config->sync_images_enabled,
            'deactivate_missing_enabled' => (bool) $config->deactivate_missing_enabled,
            'shop_id' => $config->shop_id,
            'shop_code' => $config->shop?->code,
        ];
    }

    /**
     * Преобразовать событие в атрибуты модели (для входящих событий)
     * Не используется — admin_back только публикует
     */
    public function fromEvent(array $data): array
    {
        return [
            'integration_type' => $data['integration_type'] ?? '',
            'driver' => $data['driver'] ?? '',
            'credentials' => $data['credentials'] ?? [],
            'is_active' => $data['is_active'] ?? false,
        ];
    }
}
