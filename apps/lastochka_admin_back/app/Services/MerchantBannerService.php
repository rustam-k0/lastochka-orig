<?php

namespace App\Services;

use App\Models\MerchantBanner;
use App\Models\Status;

class MerchantBannerService
{
    /**
     * Создание баннера в конкретном магазине из аргументов мутации.
     * Используется и синхронно (primary в MerchantBannerCreate), и из
     * CreateMerchantEntityCopiesJob при создании на несколько магазинов
     * (MID-238). Аргументы должны быть уже санитизированы.
     */
    public function createForShop(array $args, int $shopId): MerchantBanner
    {
        $banner = MerchantBanner::create([
            'title' => $args['title'],
            'link_type' => $args['link_type'] ?? MerchantBanner::LINK_TYPE_NONE,
            'product_id' => $args['product_id'] ?? null,
            'category_id' => $args['category_id'] ?? null,
            'display_category_id' => $args['display_category_id'] ?? null,
            'shop_id' => $shopId,
            'is_active' => $args['is_active'] ?? true,
            'position' => $args['position'] ?? 0,
            'custom_checkboxes' => $args['custom_checkboxes'] ?? null,
        ]);

        $this->clearIrrelevantLinkFields($banner);
        $banner->save();

        $this->fillImage($banner, $args);

        return $banner;
    }

    /**
     * Обработка изображения баннера
     */
    public function fillImage(MerchantBanner $banner, array $args): void
    {
        if (!isset($args['image'])) {
            return;
        }

        $imageData = $args['image'];

        $existingJoin = $banner->joinImage;

        if ($existingJoin) {
            if ($imageData === null) {
                $banner->deleteImg($existingJoin);
            } else {
                $existingJoin->img->update($imageData);
            }
        } elseif ($imageData !== null) {
            $banner->saveImg($imageData, Status::CODE_MERCHANT_BANNER_IMG, $banner->getTable());
        }
    }

    /**
     * Очистка полей связи в зависимости от link_type
     */
    public function clearIrrelevantLinkFields(MerchantBanner $banner, ?string $linkType = null): void
    {
        $linkType = $linkType ?? $banner->link_type;

        switch ($linkType) {
            case MerchantBanner::LINK_TYPE_NONE:
                $banner->product_id = null;
                $banner->category_id = null;
                break;
            case MerchantBanner::LINK_TYPE_PRODUCT:
                $banner->category_id = null;
                break;
            case MerchantBanner::LINK_TYPE_CATEGORY:
                $banner->product_id = null;
                break;
        }
    }
}
