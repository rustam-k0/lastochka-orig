<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\PromocodeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Promocode
 */
final class PromocodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => (string) $this->code,
            'percent' => $this->type === PromocodeType::Percent
                ? (int) $this->value
                : null,
        ];
    }
}
