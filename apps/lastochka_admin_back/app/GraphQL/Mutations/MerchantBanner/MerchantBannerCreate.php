<?php

namespace App\GraphQL\Mutations\MerchantBanner;

use App\Models\MerchantBanner;
use App\Services\MerchantBannerService;
use App\Services\MerchantMultiShop\MultiShopCreateService;
use Illuminate\Support\Facades\DB;

final class MerchantBannerCreate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantBanner
    {
        $args = $this->sanitizeInput($args);
        $args = \App\Services\CustomCheckboxService::sanitizeArgs('MerchantBanner', $args);

        $targets = MultiShopCreateService::resolveTargets($args);

        if (empty($targets['primary'])) {
            throw new \InvalidArgumentException('ID магазина не определён. У пользователя нет привязанного магазина.');
        }

        $banner = DB::transaction(function () use ($args, $targets) {
            return (new MerchantBannerService())->createForShop($args, $targets['primary']);
        });

        // Копии в остальные магазины — фоновой джобой (MID-238)
        MultiShopCreateService::dispatchCopies(
            \App\Jobs\MerchantMultiShop\CreateMerchantEntityCopiesJob::ENTITY_BANNER,
            $args,
            $targets,
        );

        return $banner->fresh(['image', 'shop', 'product', 'category', 'displayCategory']);
    }

    /**
     * Санитизация входных данных в зависимости от роли пользователя
     */
    protected function sanitizeInput(array $args): array
    {
        $user = auth()->user();

        if ($user->isShopScope()) {
            $userShop = $user->shops()->first();
            if ($userShop) {
                $args['shop_id'] = $userShop->shop_id;
            }

            // Мультимагазинное создание доступно только глобальным ролям
            unset($args['shop_ids'], $args['all_shops']);
        }

        return $args;
    }
}
