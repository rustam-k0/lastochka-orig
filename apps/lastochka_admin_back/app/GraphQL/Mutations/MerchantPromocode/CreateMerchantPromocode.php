<?php

namespace App\GraphQL\Mutations\MerchantPromocode;

use App\Models\MerchantPromocode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CreateMerchantPromocode
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantPromocode
    {
        $args = $this->sanitizeInput($args);

        $scope = $args['scope'] ?? MerchantPromocode::SCOPE_STORE;

        if ($scope === MerchantPromocode::SCOPE_STORE && empty($args['shop_id'])) {
            throw new \InvalidArgumentException('ID магазина не определён. У пользователя нет привязанного магазина.');
        }

        return DB::transaction(function () use ($args, $scope) {
            $discountType = $args['discount_type'] ?? 'percent';
            $shopId = $scope === MerchantPromocode::SCOPE_PLATFORM ? null : $args['shop_id'];
            $code = mb_strtoupper(trim($args['code']));

            MerchantPromocode::releaseTrashedCode($shopId, $code);

            $promocode = MerchantPromocode::create([
                'shop_id' => $shopId,
                'scope' => $scope,
                'title' => $args['title'],
                'code' => $code,
                'discount_type' => $discountType,
                'discount_value' => $args['discount_value'],
                'discount_percent' => $discountType === 'percent' ? $args['discount_value'] : 0,
                'valid_from' => $args['valid_from'],
                'valid_to' => $args['valid_to'],
                'is_active' => $args['is_active'] ?? true,
                'max_uses' => $args['max_uses'] ?? null,
                'max_uses_per_client' => $args['max_uses_per_client'] ?? null,
                'binding_type' => $args['binding_type'] ?? 'all',
            ]);

            $this->syncBindings($promocode, $args);

            // Touch для триггера updated event в outbox — чтобы products/categories попали в payload
            if (($args['binding_type'] ?? 'all') !== 'all') {
                $promocode->touch();
            }

            return $promocode->fresh(['shop', 'products', 'categories']);
        });
    }

    /**
     * Синхронизация привязок товаров/категорий
     */
    protected function syncBindings(MerchantPromocode $promocode, array $args): void
    {
        $bindingType = $args['binding_type'] ?? 'all';

        if ($bindingType === 'products' && !empty($args['product_ids'])) {
            $promocode->products()->sync($args['product_ids']);
            $promocode->categories()->detach();
        } elseif ($bindingType === 'categories' && !empty($args['category_ids'])) {
            $promocode->categories()->sync($args['category_ids']);
            $promocode->products()->detach();
        } else {
            $promocode->products()->detach();
            $promocode->categories()->detach();
        }
    }

    /**
     * Санитизация входных данных в зависимости от роли пользователя.
     * Селлеры могут создавать только scope=store промокоды под свой магазин.
     */
    protected function sanitizeInput(array $args): array
    {
        $user = auth()->user();

        if ($user->isShopScope()) {
            // Селлерам platform-wide запрещён.
            if (($args['scope'] ?? MerchantPromocode::SCOPE_STORE) === MerchantPromocode::SCOPE_PLATFORM) {
                throw new AccessDeniedHttpException('Селлер не может создавать platform-wide промокоды.');
            }

            $args['scope'] = MerchantPromocode::SCOPE_STORE;

            $userShop = $user->shops()->first();
            if ($userShop) {
                $args['shop_id'] = $userShop->shop_id;
            }
        }

        return $args;
    }
}
