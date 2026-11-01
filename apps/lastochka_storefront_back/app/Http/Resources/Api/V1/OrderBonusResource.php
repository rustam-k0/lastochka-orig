<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderBonus
 */
final class OrderBonusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'percent' => (int) $this->percent,
            'eligibleAmount' => (int) $this->eligible_amount,
            'amount' => (int) $this->amount,
        ];
    }
}
