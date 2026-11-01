<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Comparison\AttributeUnionBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Comparison */
final class ComparisonDetailsResource extends JsonResource
{
    /**
     * @return array{
     *     categoryId: int,
     *     categoryTitle: string,
     *     products: \Illuminate\Http\Resources\Json\AnonymousResourceCollection,
     *     attributes: list<array{code: string, name: string, position: int}>
     * }
     */
    public function toArray(Request $request): array
    {
        $products = $this->products;

        return [
            'categoryId' => (int) $this->category_id,
            'categoryTitle' => (string) ($this->category?->name ?? ''),
            'products' => ComparisonProductResource::collection($products),
            'attributes' => app(AttributeUnionBuilder::class)->build($products),
        ];
    }
}
