<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EstimateItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'quantity' => (int) $this->quantity,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
