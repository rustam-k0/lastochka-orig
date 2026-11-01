<?php

namespace App\GraphQL\Mutations\MerchantPromocode;

use App\Models\MerchantPromocode;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class DeleteMerchantPromocode
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): array
    {
        $promocode = MerchantPromocode::findOrFail($args['id']);

        $this->checkAccess($promocode);

        $promocode->delete();

        return [
            'success' => true,
            'message' => 'Промокод успешно удалён',
        ];
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
