<?php

namespace App\GraphQL\Mutations\Brand;

use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Support\Facades\DB;

final class BrandCreate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Brand
    {
        return DB::transaction(function () use ($args) {
            $args = \App\Services\CustomCheckboxService::sanitizeArgs('Brand', $args);
            $service = new BrandService();

            $brand = Brand::create(array_merge([
                'title' => $args['title'],
                'link' => $args['link'] ?? null,
                'country_origin' => $args['country_origin'] ?? null,
                'country_production' => $args['country_production'] ?? null,
                'certificate_path' => $args['certificate_path'] ?? null,
                'certificate_name' => $args['certificate_name'] ?? null,
                'position' => $args['position'] ?? 0,
                'is_active' => $args['is_active'] ?? true,
                'custom_checkboxes' => $args['custom_checkboxes'] ?? null,
            ], $service->resolveCountryFields($args)));

            $service->fillLogo($brand, $args);

            return $brand->fresh(['logoImage']);
        });
    }
}
