<?php

namespace App\GraphQL\Mutations\Brand;

use App\Models\Brand;
use App\Services\BrandService;
use Illuminate\Support\Facades\DB;

final class BrandUpdate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Brand
    {
        return DB::transaction(function () use ($args) {
            $brand = Brand::findOrFail($args['id']);

            $args = \App\Services\CustomCheckboxService::sanitizeArgs('Brand', $args);
            $updateData = array_filter([
                'title' => $args['title'] ?? null,
                'position' => $args['position'] ?? null,
                'is_active' => $args['is_active'] ?? null,
            ], function ($value) {
                return $value !== null;
            });

            if (isset($args['is_active'])) {
                $updateData['is_active'] = $args['is_active'];
            }

            // Nullable-поля блока бренда: явный null очищает значение,
            // отсутствие ключа — не трогает.
            foreach (['link', 'country_origin', 'country_production', 'certificate_path', 'certificate_name'] as $field) {
                if (array_key_exists($field, $args)) {
                    $updateData[$field] = $args[$field];
                }
            }

            if (array_key_exists('custom_checkboxes', $args)) {
                $updateData['custom_checkboxes'] = $args['custom_checkboxes'];
            }

            $service = new BrandService();

            // Коды стран перекрывают переданные названия: название берётся из справочника.
            $updateData = array_merge($updateData, $service->resolveCountryFields($args));

            if (!empty($updateData)) {
                $brand->update($updateData);
            }

            $service->fillLogo($brand, $args);

            return $brand->fresh(['logoImage']);
        });
    }
}
