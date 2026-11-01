<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Brand
 */
final class BrandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'link' => $this->link,
            'countryOrigin' => $this->country_origin,
            'countryOriginCode' => $this->country_origin_code,
            'countryProduction' => $this->country_production,
            'countryProductionCode' => $this->country_production_code,
            'rating' => $this->resource->productsRatingAvg(),
            'certificate' => $this->certificate_path ? ImageResource::toFullUrl($this->certificate_path) : null,
            'certificateName' => $this->certificate_name,
            'logoImage' => ImageResource::fromPath($this->logo_path, $this->id),
            'customCheckboxes' => (object) ($this->custom_checkboxes ?? []),
        ];
    }
}
