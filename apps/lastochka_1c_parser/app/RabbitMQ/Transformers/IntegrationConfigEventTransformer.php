<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\IntegrationConfig;
use App\Services\OneCConfigService;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IntegrationConfigEventTransformer implements EventTransformer
{
    public function toEvent(Model $config, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $config->id,
            'source' => config('rabbitmq-events.source'),
        ];
    }

    public function fromEvent(array $data): array
    {
        $attributes = [
            'integration_type' => $data['integration_type'],
            'driver' => $data['driver'] ?? 'commerceml',
            'credentials' => $data['credentials'] ?? [],
            'is_active' => $data['is_active'] ?? false,
            'shop_id' => $data['shop_id'] ?? null,
            'shop_code' => $data['shop_code'] ?? null,
        ];

        if (array_key_exists('sync_categories_enabled', $data)) {
            $attributes['sync_categories_enabled'] = (bool) $data['sync_categories_enabled'];
        }

        if (array_key_exists('sync_images_enabled', $data)) {
            $attributes['sync_images_enabled'] = (bool) $data['sync_images_enabled'];
        }

        if (array_key_exists('deactivate_missing_enabled', $data)) {
            $attributes['deactivate_missing_enabled'] = (bool) $data['deactivate_missing_enabled'];
        }

        return $attributes;
    }

    public function validate(array $data): bool
    {
        return isset($data['event_id'], $data['id'], $data['integration_type']);
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $query = IntegrationConfig::where('integration_type', $eventData['integration_type']);
        if (isset($eventData['shop_id'])) {
            $query->where('shop_id', $eventData['shop_id']);
        }
        return $query->first();
    }

    public function afterCreate(Model $config, array $eventData): void
    {
        if ($config instanceof IntegrationConfig) {
            OneCConfigService::syncUserFromConfig($config);
        }
    }

    public function afterUpdate(Model $config, array $eventData): void
    {
        if ($config instanceof IntegrationConfig) {
            OneCConfigService::syncUserFromConfig($config);
        }
    }
}
