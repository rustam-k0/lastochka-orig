<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\LinkType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Banner
 */
final class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'storeId' => (int) $this->store_id,
            'title' => (string) $this->title,
            'image' => ImageResource::fromPath($this->image_path, $this->id),
            /** @var LinkType Тип ссылки: none, product, category */
            'linkType' => $this->link_type->value,
            'linkProductId' => $this->link_product_id ? (int) $this->link_product_id : null,
            'linkCategoryId' => $this->link_category_id ? (int) $this->link_category_id : null,
            /** ID категории, на странице которой показывается баннер (null = баннер главной) */
            'displayCategoryId' => $this->display_category_id ? (int) $this->display_category_id : null,
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];
    }
}
