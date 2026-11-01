<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CartItemSupplement
 */
final class CartItemSupplementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $supplement = $this->whenLoaded('supplement');

        return [
            'supplementId' => (int) $this->supplement_id,
            'title' => $supplement ? (string) $supplement->title : null,
            'type' => $supplement ? (string) $supplement->type : null,
            'values' => SupplementValueResource::collection($this->whenLoaded('values')),
        ];
    }
}
