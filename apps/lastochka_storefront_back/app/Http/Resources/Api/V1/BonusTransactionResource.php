<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\BonusTransaction
 */
final class BonusTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'amount' => (int) $this->amount,
            'balanceAfter' => (int) $this->balance_after,
            'description' => $this->description,
            'orderId' => $this->order_id ? (int) $this->order_id : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
