<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\LinkType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Story
 */
final class StoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'thumbnailImage' => ImageResource::fromPath($this->thumbnail_path, $this->id),
            'images' => ImageResource::collection($this->whenLoaded('images')),
            /** @var LinkType Тип ссылки: для сториз обычно none, product, store, external */
            'linkType' => $this->link_type->value,
            'linkProductId' => $this->link_product_id ? (int) $this->link_product_id : null,
            'linkStoreId' => $this->link_store_id ? (int) $this->link_store_id : null,
            'linkExternalUrl' => $this->link_external_url,
            'isQuickCategory' => (bool) $this->is_quick_category,
            /** @format date-time */
            'expiresAt' => $this->expires_at->toIso8601String(),
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];
    }
}
