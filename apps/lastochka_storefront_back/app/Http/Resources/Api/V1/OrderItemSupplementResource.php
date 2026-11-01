<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderItemSupplement
 */
final class OrderItemSupplementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $supplement = $this->whenLoaded('supplement');

        return [
            'supplementId' => (int) $this->supplement_id,
            'title' => $supplement ? (string) $supplement->title : null,
            'amount' => (int) $this->amount,
            'values' => $this->whenLoaded('values', function () {
                return $this->values->map(fn ($value) => [
                    'id' => (int) $value->id,
                    'title' => (string) $value->title,
                    'amount' => (int) $value->pivot->amount,
                ]);
            }),
        ];
    }
}
