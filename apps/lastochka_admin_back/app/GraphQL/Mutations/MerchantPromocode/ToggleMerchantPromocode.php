<?php

namespace App\GraphQL\Mutations\MerchantPromocode;

use App\Models\MerchantPromocode;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ToggleMerchantPromocode
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantPromocode
    {
        $promocode = MerchantPromocode::findOrFail($args['id']);

        $this->checkAccess($promocode);

        $promocode->update(['is_active' => $args['is_active']]);

        return $promocode->fresh(['shop']);
    }

    /**
     * Проверка доступа к промокоду
     */
    protected function checkAccess(MerchantPromocode $promocode): void
    {
        $user = auth()->user();

        if ($user->isGlobalScope()) {
            return;
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        if (!in_array($promocode->shop_id, $shopIds)) {
            throw new AccessDeniedHttpException('У вас нет доступа к этому промокоду.');
        }
    }
}
