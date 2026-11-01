<?php

namespace App\Transformers;

use App\Models\ProductGroupFilter;
use Comrades\RabbitMQEvents\Concerns\HasEventHandlerHooks;
use Comrades\RabbitMQEvents\Concerns\ValidatesEventData;
use Comrades\RabbitMQEvents\Contracts\EventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductGroupFilterEventTransformer implements EventTransformer
{
    use ValidatesEventData;
    use HasEventHandlerHooks;

    protected array $requiredFields = ['event_id', 'id', 'source'];

    /**
     * @param ProductGroupFilter $filter
     */
    public function toEvent(Model $filter, ?object $relatedModel = null, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_timestamp' => $filter->updated_at ?? now(),
            'id' => (string) $filter->id,
            'source' => config('rabbitmq-events.source'),
            'name' => $filter->name,
            'position' => (int) $filter->position,
            'is_active' => (bool) $filter->is_active,
            'values' => $filter->values->map(fn ($v) => [
                'id' => (string) $v->id,
                'value' => $v->value,
                'image_path' => $v->image_path,
                'position' => (int) $v->position,
                'is_active' => (bool) $v->is_active,
            ])->toArray(),
            'created_at' => $filter->created_at,
            'updated_at' => $filter->updated_at,
            'deleted_at' => $filter->deleted_at,
        ];
    }

    public function fromEvent(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'position' => $data['position'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $name = $eventData['name'] ?? null;
        if ($name) {
            return ProductGroupFilter::withTrashed()->where('name', $name)->first();
        }
        return null;
    }
}
