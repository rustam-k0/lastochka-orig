<?php

namespace App\Transformers;

use App\Models\City;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CityEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = [
        'event_id',
        'id',
        'source',
    ];

    /**
     * @param City $city
     */
    public function toEvent(Model $city, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $city->updated_at ?? now(),
            'id' => (string) $city->id,
            'source' => config('rabbitmq-events.source'),
            'name' => $city->name,
            'latitude' => $city->latitude ? (float) $city->latitude : null,
            'longitude' => $city->longitude ? (float) $city->longitude : null,
            'is_active' => (bool) $city->is_active,
            'is_default' => (bool) $city->is_default,
            'created_at' => $city->created_at,
            'updated_at' => $city->updated_at,
            'deleted_at' => $city->deleted_at,
        ];
    }

    public function fromEvent(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false,
        ];
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $name = $eventData['name'] ?? null;
        if ($name) {
            return City::withTrashed()->where('name', $name)->first();
        }
        return null;
    }
}
