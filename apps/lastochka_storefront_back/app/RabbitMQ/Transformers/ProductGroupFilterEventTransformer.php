<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Models\ProductGroupFilter;
use App\Models\ProductGroupFilterValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ProductGroupFilterEventTransformer extends BaseEventTransformer
{
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source'];
    }

    public function toEvent(Model $filter, ?object $related = null, ?string $eventId = null): array
    {
        return [];
    }

    public function fromEvent(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'position' => $data['position'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    public function afterCreate(Model $filter, array $eventData): void
    {
        $this->syncValues($filter, $eventData['values'] ?? []);
    }

    public function afterUpdate(Model $filter, array $eventData): void
    {
        if (isset($eventData['values'])) {
            $this->syncValues($filter, $eventData['values']);
        }
    }

    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $name = $eventData['name'] ?? null;
        if ($name) {
            return ProductGroupFilter::withTrashed()->where('name', $name)->first();
        }
        return null;
    }

    private function syncValues(ProductGroupFilter $filter, array $values): void
    {
        if (empty($values)) {
            return;
        }

        DB::transaction(function () use ($filter, $values) {
            $keepIds = [];

            foreach ($values as $valueData) {
                $filterValue = ProductGroupFilterValue::withTrashed()
                    ->where('product_group_filter_id', $filter->id)
                    ->where('value', $valueData['value'])
                    ->first();

                if ($filterValue) {
                    $filterValue->update([
                        'image_path' => $valueData['image_path'] ?? null,
                        'position' => $valueData['position'] ?? 0,
                        'is_active' => $valueData['is_active'] ?? true,
                        'deleted_at' => null,
                    ]);
                } else {
                    $filterValue = ProductGroupFilterValue::create([
                        'product_group_filter_id' => $filter->id,
                        'value' => $valueData['value'],
                        'image_path' => $valueData['image_path'] ?? null,
                        'position' => $valueData['position'] ?? 0,
                        'is_active' => $valueData['is_active'] ?? true,
                    ]);
                }

                $keepIds[] = $filterValue->id;
            }

            ProductGroupFilterValue::where('product_group_filter_id', $filter->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        });
    }
}
