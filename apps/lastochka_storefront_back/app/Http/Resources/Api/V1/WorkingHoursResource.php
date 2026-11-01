<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\DTOs\Store\WorkingHours
 */
final class WorkingHoursResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'openingHour' => (int) $this->openingHour,
            'openingMinute' => (int) $this->openingMinute,
            'closingHour' => (int) $this->closingHour,
            'closingMinute' => (int) $this->closingMinute,
        ];
    }
}
