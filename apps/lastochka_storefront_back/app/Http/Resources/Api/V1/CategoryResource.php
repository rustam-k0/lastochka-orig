<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Category
 */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Тег виден только у конечной категории с напрямую привязанными товарами
        // (LASTOCHKI-JAMI-11874). Счётчики не загружены → null (тег скрыт).
        $showTag = $this->children_count === 0
            && ($this->products_count ?? 0) > 0
            && $this->tag_text !== null
            && $this->tag_text !== '';

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'position' => (int) ($this->position ?? 0),
            'image' => ImageResource::fromPath($this->image_path, $this->id),
            'backgroundImage' => ImageResource::fromPath($this->background_image_path, $this->id),
            'isLeaf' => $this->when($this->children_count !== null, fn () => $this->children_count === 0),
            'tagText' => $showTag ? (string) $this->tag_text : null,
            'tagColor' => $showTag ? $this->tag_color : null,
            'parent' => $this->when($this->parent_id !== null, fn () => new CategoryResource($this->whenLoaded('parent'))),
            'children' => $this->when(
                $this->relationLoaded('children'),
                fn () => CategoryResource::collection($this->children),
                null
            ),
            'products' => $this->when(
                $this->relationLoaded('products'),
                fn () => ProductResource::collection($this->products),
            ),
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];
    }
}
