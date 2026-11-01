<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\City;
use Illuminate\Database\Eloquent\Model;

final class CityEventTransformer extends BaseEventTransformer
{
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source'];
    }

    /**
     * Не используется — только consume.
     */
    public function toEvent(Model $city, ?object $related = null, ?string $eventId = null): array
    {
        return [];
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
