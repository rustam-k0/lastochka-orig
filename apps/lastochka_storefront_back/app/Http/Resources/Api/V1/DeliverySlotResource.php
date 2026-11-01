<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Delivery\DeliverySlotData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliverySlotData
 */
final class DeliverySlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'timeSlot' => (string) $this->timeSlot,
            'date' => $this->startAt->toIso8601String(),
        ];
    }
}
