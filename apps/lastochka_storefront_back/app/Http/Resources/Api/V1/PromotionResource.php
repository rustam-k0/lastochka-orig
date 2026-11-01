<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Promotion
 */
final class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => ImageResource::fromPath($this->image_path, $this->id),
            'store' => $this->whenLoaded('store', fn () => [
                'id' => (int) $this->store->id,
                'name' => (string) $this->store->name,
                'description' => $this->store->description,
            ]),
            'discountPercent' => (int) $this->discount_percent,
            /** @format date-time */
            'startsAt' => $this->starts_at->toIso8601String(),
            /** @format date-time */
            'endsAt' => $this->ends_at->toIso8601String(),
            'productsCount' => (int) ($this->products_count ?? $this->products()->count()),
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];
    }
}
