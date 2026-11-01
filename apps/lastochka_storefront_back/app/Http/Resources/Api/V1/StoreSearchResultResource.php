<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ресурс для результата поиска с группировкой по магазинам.
 *
 * @property-read \App\Models\Store $store
 * @property-read \Illuminate\Support\Collection $products
 * @property-read int $totalInStore
 */
final class StoreSearchResultResource extends JsonResource
{
    /**
     * @param  array{store: \App\Models\Store, products: \Illuminate\Support\Collection, totalInStore: int}  $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct((object) $resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'store' => new StoreResource($this->resource->store),
            'products' => ProductResource::collection($this->resource->products),
            'totalInStore' => (int) $this->resource->totalInStore,
        ];
    }
}
