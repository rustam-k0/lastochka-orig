<?php

namespace App\Services;

use App\Models\MerchantSale;
use App\Models\Status;

class MerchantSaleService
{
    /**
     * Создание акции в конкретном магазине из аргументов мутации.
     * Используется и синхронно (primary в CreateMerchantSale), и из
     * CreateMerchantEntityCopiesJob при создании на несколько магазинов
     * (MID-237). Аргументы должны быть уже санитизированы.
     */
    public function createForShop(array $args, int $shopId): MerchantSale
    {
        $sale = MerchantSale::create([
            'title' => $args['title'],
            'description' => $args['description'] ?? null,
            'shop_id' => $shopId,
            'discount_percent' => $args['discount_percent'],
            'starts_at' => $args['starts_at'],
            'ends_at' => $args['ends_at'],
            'is_active' => $args['is_active'] ?? true,
            'custom_checkboxes' => $args['custom_checkboxes'] ?? null,
        ]);

        if (!empty($args['product_ids'])) {
            $this->syncProducts($sale, $args['product_ids']);
        }

        $this->fillImage($sale, $args);

        return $sale;
    }

    /**
     * Обработка изображения акции
     */
    public function fillImage(MerchantSale $sale, array $args): void
    {
        if (!isset($args['image'])) {
            return;
        }

        $imageData = $args['image'];

        $existingJoin = $sale->joinImage;

        if ($existingJoin) {
            if ($imageData === null) {
                $sale->deleteImg($existingJoin);
            } else {
                $existingJoin->img->update($imageData);
            }
        } elseif ($imageData !== null) {
            $sale->saveImg($imageData, Status::CODE_MERCHANT_SALE_IMG, $sale->getTable());
        }
    }

    /**
     * Синхронизация товаров акции
     */
    public function syncProducts(MerchantSale $sale, array $productIds): void
    {
        $sale->syncProducts($productIds);
    }
}
