<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Pricing\ProductPriceResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
final class ComparisonProductResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     slug: string|null,
     *     price: float,
     *     weight: int|null,
     *     measurementUnit: string,
     *     measurementUnitLabel: string,
     *     imageUrl: string|null,
     *     available: bool,
     *     attributes: list<array{code: string, value: string}>
     * }
     */
    public function toArray(Request $request): array
    {
        $image = $this->images->first();

        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'slug' => $this->slug,
            // Цены от количества: сравнение показывает цену за одну порцию
            'price' => (float) app(ProductPriceResolver::class)->quote($this->resource, 1.0)->price,
            'weight' => $this->weight !== null ? (int) $this->weight : null,
            'measurementUnit' => $this->measurement_unit?->value ?? 'piece',
            'measurementUnitLabel' => $this->measurement_unit?->label() ?? 'шт',
            'imageUrl' => $image?->path ? (string) $image->path : null,
            'available' => $this->isAvailable(),
            'adultOnly' => (bool) $this->adult_only,
            'pickupOnly' => (bool) $this->pickup_only,
            'attributes' => $this->attributes
                ->map(fn ($attribute): array => [
                    'code' => (string) $attribute->code,
                    'value' => (string) ($attribute->value ?? ''),
                ])
                ->values()
                ->all(),
        ];
    }
}
