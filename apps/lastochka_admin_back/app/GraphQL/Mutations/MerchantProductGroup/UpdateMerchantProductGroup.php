<?php

namespace App\GraphQL\Mutations\MerchantProductGroup;

use App\Enums\ProductGroupFillType;
use App\Models\MerchantProductGroup;
use App\Services\MerchantProductGroupService;
use Illuminate\Support\Facades\DB;

final class UpdateMerchantProductGroup
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantProductGroup
    {
        $args = $this->sanitizeInput($args);

        return DB::transaction(function () use ($args) {
            $group = MerchantProductGroup::findOrFail($args['id']);

            $args = \App\Services\CustomCheckboxService::sanitizeArgs('MerchantProductGroup', $args);
            $updateData = array_filter([
                'title' => $args['title'] ?? null,
                'description' => $args['description'] ?? null,
                'meta' => $args['meta'] ?? null,
            ], function ($value) {
                return $value !== null;
            });

            if (isset($args['show_on_main'])) {
                $updateData['show_on_main'] = $args['show_on_main'];
            }

            if (isset($args['show_on_merchant_page'])) {
                $updateData['show_on_merchant_page'] = $args['show_on_merchant_page'];
            }

            if (isset($args['show_in_merchant_catalog'])) {
                $updateData['show_in_merchant_catalog'] = $args['show_in_merchant_catalog'];
            }

            if (isset($args['show_on_search'])) {
                $updateData['show_on_search'] = $args['show_on_search'];
            }

            if (isset($args['sort_order'])) {
                $updateData['sort_order'] = $args['sort_order'];
            }

            if (isset($args['is_active'])) {
                $updateData['is_active'] = $args['is_active'];
            }

            if (isset($args['is_recommended'])) {
                $updateData['is_recommended'] = $args['is_recommended'];
            }

            if (isset($args['is_cart_page'])) {
                $updateData['is_cart_page'] = $args['is_cart_page'];
            }

            if (isset($args['is_complex_solution'])) {
                $updateData['is_complex_solution'] = $args['is_complex_solution'];
            }

            // Итоговое состояние флага: комплексное решение и места размещения взаимоисключающие
            $isComplexSolution = (bool) ($args['is_complex_solution'] ?? $group->is_complex_solution);

            if ($isComplexSolution) {
                $updateData['show_on_main'] = false;
                $updateData['show_on_merchant_page'] = false;
                $updateData['show_in_merchant_catalog'] = false;
                $updateData['show_on_search'] = false;
                $updateData['is_recommended'] = false;
                $updateData['is_cart_page'] = false;
            }

            if (array_key_exists('custom_checkboxes', $args)) {
                $updateData['custom_checkboxes'] = $args['custom_checkboxes'];
            }

            if (isset($args['fill_type'])) {
                $updateData['fill_type'] = $args['fill_type'];
            }

            // Итоговый способ наполнения: у автоматической группы товары собирает
            // витрина по критерию, ручной состав очищается и не принимается.
            $fillType = ProductGroupFillType::tryFrom((string) ($args['fill_type'] ?? $group->fill_type))
                ?? ProductGroupFillType::MANUAL;

            if (!empty($updateData)) {
                $group->update($updateData);
            }

            $service = new MerchantProductGroupService();

            if (!$fillType->isManual()) {
                $group->syncProducts([]);
            } elseif (isset($args['products_with_qty'])) {
                $group->syncProducts($args['products_with_qty']);
            } elseif (isset($args['product_ids'])) {
                $group->syncProducts($args['product_ids']);
            }

            if (!$isComplexSolution) {
                // Не решение — значения фильтров комплексных решений отвязываются
                $group->filterValues()->detach();
            } elseif (isset($args['filter_value_ids'])) {
                $group->filterValues()->sync($args['filter_value_ids']);
            }

            if (array_key_exists('image', $args)) {
                $service->fillImage($group, $args);
            }

            $group->touch();

            return $group->fresh(['image', 'shop', 'products']);
        });
    }

    /**
     * Санитизация входных данных в зависимости от роли пользователя
     */
    protected function sanitizeInput(array $args): array
    {
        $user = auth()->user();

        // Не-админы не могут управлять show_on_main
        if (!$user->isGlobalScope()) {
            unset($args['show_on_main']);
        }

        return $args;
    }
}
