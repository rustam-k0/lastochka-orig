<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Comparison */
final class ComparisonGroupResource extends JsonResource
{
    /**
     * @return array{categoryId: int, categoryTitle: string, count: int}
     */
    public function toArray(Request $request): array
    {
        $count = $this->items_count
            ?? (isset($this->resource->items) && $this->resource->relationLoaded('items')
                ? $this->items->count()
                : $this->items()->count());

        return [
            'categoryId' => (int) $this->category_id,
            'categoryTitle' => (string) ($this->category?->name ?? ''),
            'count' => (int) $count,
        ];
    }
}
