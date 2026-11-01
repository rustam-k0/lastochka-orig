<?php

namespace App\GraphQL\Mutations\MerchantBanner;

use App\Models\MerchantBanner;
use App\Services\MerchantBannerService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class MerchantBannerUpdate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantBanner
    {
        return DB::transaction(function () use ($args) {
            $banner = MerchantBanner::findOrFail($args['id']);

            $this->checkAccess($banner);

            $args = \App\Services\CustomCheckboxService::sanitizeArgs('MerchantBanner', $args);
            $updateData = [];

            if (isset($args['title'])) {
                $updateData['title'] = $args['title'];
            }

            if (isset($args['link_type'])) {
                $updateData['link_type'] = $args['link_type'];
            }

            if (isset($args['product_id'])) {
                $updateData['product_id'] = $args['product_id'];
            }

            if (isset($args['category_id'])) {
                $updateData['category_id'] = $args['category_id'];
            }

            // array_key_exists, а не isset: явный null снимает привязку
            // и возвращает баннер на главную страницу
            if (array_key_exists('display_category_id', $args)) {
                $updateData['display_category_id'] = $args['display_category_id'];
            }

            if (isset($args['is_active'])) {
                $updateData['is_active'] = $args['is_active'];
            }

            if (isset($args['position'])) {
                $updateData['position'] = $args['position'];
            }

            if (array_key_exists('custom_checkboxes', $args)) {
                $updateData['custom_checkboxes'] = $args['custom_checkboxes'];
            }

            if (!empty($updateData)) {
                $banner->update($updateData);
            }

            $service = new MerchantBannerService();
            $service->clearIrrelevantLinkFields($banner);
            $banner->save();

            if (array_key_exists('image', $args)) {
                $service->fillImage($banner, $args);

                // Картинка живёт в img/join_img и не меняет модель MerchantBanner:
                // без touch() image-only правка не создаёт outbox-событие
                // merchant_banner.updated и не синкается в storefront
                // (LASTOCHKI-JAMI-12236). wasChanged() защищает от дубля, когда
                // update()/save() выше уже опубликовали событие.
                if (!$banner->wasChanged()) {
                    $banner->touch();
                }
            }

            return $banner->fresh(['image', 'shop', 'product', 'category', 'displayCategory']);
        });
    }

    /**
     * Проверка доступа к баннеру
     */
    protected function checkAccess(MerchantBanner $banner): void
    {
        $user = auth()->user();

        if ($user->isGlobalScope()) {
            return;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        if (!in_array($banner->shop_id, $shopIds)) {
            throw new AccessDeniedHttpException('У вас нет доступа к этому баннеру.');
        }
    }
}
