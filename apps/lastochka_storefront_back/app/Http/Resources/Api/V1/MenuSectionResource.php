<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\DTOs\Store\MenuSectionData
 */
final class MenuSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'image' => ImageResource::fromPath($this->imagePath, $this->id),
            'sortOrder' => $this->sortOrder,
            'products' => ProductResource::collection($this->products),
        ];
    }
}
