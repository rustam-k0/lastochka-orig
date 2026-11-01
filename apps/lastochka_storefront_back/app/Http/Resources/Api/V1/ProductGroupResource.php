<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductGroup
 */
final class ProductGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => ImageResource::fromPath($this->image_path, $this->id),
            'meta' => $this->meta,
            // Способ наполнения: manual | purchase_history | viewed_not_bought | is_new | is_hurry_up
            'fillType' => $this->resource->fillType()->value,
            'productsCount' => (int) ($this->products_count ?? $this->products->count()),
            'products' => ProductResource::collection($this->whenLoaded('products')),
            'store' => new StoreResource($this->whenLoaded('store')),
            'is_recommended' => (bool) $this->is_recommended,
            'isComplexSolution' => (bool) $this->is_complex_solution,
            'isFavorite' => $this->resource->isFavorite($request->user()),
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];
    }
}
