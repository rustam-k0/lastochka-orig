<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderPromocode
 */
final class OrderPromocodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => (string) $this->code,
            'type' => $this->type->value,
            'value' => (int) $this->value,
            'discountAmount' => (float) $this->discount_amount,
        ];
    }
}
