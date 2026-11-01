<?php

namespace App\GraphQL\Mutations\MerchantBanner;

use App\Models\MerchantBanner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class MerchantBannerDelete
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantBanner
    {
        $banner = MerchantBanner::findOrFail($args['id']);

        $this->checkAccess($banner);

        $banner->delete();

        return $banner;
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
