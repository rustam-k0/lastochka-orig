<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PaymentCard
 */
final class PaymentCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'cardType' => $this->card_type,
            'lastFour' => $this->last_four,
            'expiryMonth' => $this->expiry_month,
            'expiryYear' => $this->expiry_year,
            'isDefault' => (bool) $this->is_default,
        ];
    }
}
