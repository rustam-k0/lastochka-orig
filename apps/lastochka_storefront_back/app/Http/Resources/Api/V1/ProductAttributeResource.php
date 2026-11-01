<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ProductAttribute
 */
final class ProductAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'key' => (string) $this->name,
            'value' => (string) $this->value,
            'description' => $this->description,
            'group' => $this->group_code ?? 'general',
            'sortOrder' => (int) $this->position,
        ];
    }
}
