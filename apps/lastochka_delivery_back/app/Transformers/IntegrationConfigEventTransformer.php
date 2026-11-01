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

    public function toEvent(Model $config, ?object $relatedModel = null, ?string $eventId = null): array
    {
        // Не публикуем — заглушка
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => now()->toIso8601String(),
            'id' => (string) $config->id,
            'source' => config('rabbitmq-events.source'),
        ];
    }

    public function fromEvent(array $data): array
    {
        return [
            'integration_type' => $data['integration_type'],
            'driver' => $data['driver'] ?? '',
            'credentials' => $data['credentials'] ?? [],
            'is_active' => $data['is_active'] ?? false,
        ];
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        return IntegrationConfig::where('integration_type', $eventData['integration_type'])->first();
    }

    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source', 'integration_type'];
    }
}
