<?php

namespace App\Services;

use App\Enums\ProductGroupFillType;
use App\Models\MerchantProductGroup;
use App\Models\Status;

class MerchantProductGroupService
{
    /**
     * Обработка изображения группы
     */
    public function fillImage(MerchantProductGroup $group, array $args): void
    {
        if (!isset($args['image'])) {
            return;
        }

        $imageData = $args['image'];

        $existingJoin = $group->joinImage;

        if ($existingJoin) {
            if ($imageData === null) {
                $group->deleteImg($existingJoin);
            } else {
                $existingJoin->img->update($imageData);
            }
        } elseif ($imageData !== null) {
            $group->saveImg($imageData, Status::CODE_MERCHANT_PRODUCT_GROUP_IMG, $group->getTable());
        }
    }

    /**
     * Синхронизация товаров группы
     */
    public function syncProducts(MerchantProductGroup $group, array $productIds): void
    {
        $group->syncProducts($productIds);
    }

    /**
     * Создание товарной группы в конкретном магазине из аргументов мутации.
     * Используется и синхронно (primary в CreateMerchantProductGroup), и из
     * CreateMerchantEntityCopiesJob при создании на несколько магазинов
     * (MID-229). Аргументы должны быть уже санитизированы.
     */
    public function createForShop(array $args, int $shopId): MerchantProductGroup
    {
        $isComplexSolution = (bool) ($args['is_complex_solution'] ?? false);
        $fillType = ProductGroupFillType::tryFrom((string) ($args['fill_type'] ?? '')) ?? ProductGroupFillType::MANUAL;

        // Комплексное решение и места размещения взаимоисключающие
        $group = MerchantProductGroup::create([
            'shop_id' => $shopId,
            'title' => $args['title'],
            'description' => $args['description'] ?? null,
            'meta' => $args['meta'] ?? null,
            'show_on_main' => !$isComplexSolution && ($args['show_on_main'] ?? false),
            'show_on_merchant_page' => !$isComplexSolution && ($args['show_on_merchant_page'] ?? true),
            'show_in_merchant_catalog' => !$isComplexSolution && ($args['show_in_merchant_catalog'] ?? false),
            'show_on_search' => !$isComplexSolution && ($args['show_on_search'] ?? false),
            'sort_order' => $args['sort_order'] ?? 0,
            'is_active' => $args['is_active'] ?? true,
            'is_recommended' => !$isComplexSolution && ($args['is_recommended'] ?? false),
            'is_cart_page' => !$isComplexSolution && ($args['is_cart_page'] ?? false),
            'is_complex_solution' => $isComplexSolution,
            'custom_checkboxes' => $args['custom_checkboxes'] ?? null,
            'fill_type' => $fillType->value,
        ]);

        // Автоматическую группу наполняет витрина по критерию: выбранные
        // товары (product_ids / products_with_qty) игнорируются.
        if ($fillType->isManual()) {
            if (!empty($args['products_with_qty'])) {
                $group->syncProducts($args['products_with_qty']);
            } elseif (!empty($args['product_ids'])) {
                $group->syncProducts($args['product_ids']);
            }
        }

        if ($isComplexSolution && !empty($args['filter_value_ids'])) {
            $group->filterValues()->sync($args['filter_value_ids']);
        }

        // Pivot sync не стреляет updated-событие родителя. touch() публикует
        // updated с актуальными связями для любой группы, не только комплексной
        // (подстраховка к delay_seconds publisher'а, см. правило Pivot + Outbox).
        $group->touch();

        $this->fillImage($group, $args);

        return $group;
    }
}
