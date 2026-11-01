<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductGroupFilterValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
            'image' => $this->image_path
                ? ImageResource::fromPath($this->image_path, $this->id)
                : null,
            'position' => $this->position,
        ];
    }
}
