<?php

namespace App\GraphQL\Mutations\MerchantSale;

use App\Models\MerchantSale;
use App\Services\MerchantSaleService;
use Illuminate\Support\Facades\DB;

final class UpdateMerchantSale
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantSale
    {
        return DB::transaction(function () use ($args) {
            $sale = MerchantSale::findOrFail($args['id']);

            $args = \App\Services\CustomCheckboxService::sanitizeArgs('MerchantSale', $args);
            $updateData = array_filter([
                'title' => $args['title'] ?? null,
                'description' => $args['description'] ?? null,
                'shop_id' => $args['shop_id'] ?? null,
                'discount_percent' => $args['discount_percent'] ?? null,
                'starts_at' => $args['starts_at'] ?? null,
                'ends_at' => $args['ends_at'] ?? null,
            ], function ($value) {
                return $value !== null;
            });

            if (isset($args['is_active'])) {
                $updateData['is_active'] = $args['is_active'];
            }

            if (array_key_exists('custom_checkboxes', $args)) {
                $updateData['custom_checkboxes'] = $args['custom_checkboxes'];
            }

            if (!empty($updateData)) {
                $sale->update($updateData);
            }

            $service = new MerchantSaleService();

            if (isset($args['product_ids'])) {
                $service->syncProducts($sale, $args['product_ids']);
                $sale->touch();
            }

            if (array_key_exists('image', $args)) {
                $service->fillImage($sale, $args);
            }

            return $sale->fresh(['image', 'shop', 'products']);
        });
    }
}
