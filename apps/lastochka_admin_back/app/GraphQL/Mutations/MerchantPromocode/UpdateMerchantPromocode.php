<?php

namespace App\GraphQL\Mutations\MerchantPromocode;

use App\Models\MerchantPromocode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class UpdateMerchantPromocode
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantPromocode
    {
        $promocode = MerchantPromocode::findOrFail($args['id']);

        $this->checkAccess($promocode);

        return DB::transaction(function () use ($promocode, $args) {
            $data = [];

            // Смена scope (admin-only, валидация в валидаторе + checkAccess).
            if (isset($args['scope'])) {
                $data['scope'] = $args['scope'];

                if ($args['scope'] === MerchantPromocode::SCOPE_PLATFORM) {
                    $data['shop_id'] = null;
                } elseif ($args['scope'] === MerchantPromocode::SCOPE_STORE && isset($args['shop_id'])) {
                    $data['shop_id'] = $args['shop_id'];
                }
            } elseif (isset($args['shop_id']) && $promocode->scope === MerchantPromocode::SCOPE_STORE) {
                $data['shop_id'] = $args['shop_id'];
            }

            if (isset($args['title'])) {
                $data['title'] = $args['title'];
            }

            if (isset($args['code'])) {
                $data['code'] = mb_strtoupper(trim($args['code']));
            }

            if (isset($args['discount_percent'])) {
                $data['discount_percent'] = $args['discount_percent'];
            }

            if (isset($args['discount_type'])) {
                $data['discount_type'] = $args['discount_type'];
            }

            if (isset($args['discount_value'])) {
                $data['discount_value'] = $args['discount_value'];
                // Backward compat: обновляем discount_percent при типе percent
                $currentType = $args['discount_type'] ?? $promocode->discount_type;
                $data['discount_percent'] = $currentType === 'percent' ? $args['discount_value'] : 0;
            }

            if (isset($args['binding_type'])) {
                $data['binding_type'] = $args['binding_type'];
            }

            if (isset($args['valid_from'])) {
                $data['valid_from'] = $args['valid_from'];
            }

            if (isset($args['valid_to'])) {
                $data['valid_to'] = $args['valid_to'];
            }

            if (isset($args['is_active'])) {
                $data['is_active'] = $args['is_active'];
            }

            if (array_key_exists('max_uses', $args)) {
                $data['max_uses'] = $args['max_uses'];
            }

            if (array_key_exists('max_uses_per_client', $args)) {
                $data['max_uses_per_client'] = $args['max_uses_per_client'];
            }

            if (isset($data['code']) || array_key_exists('shop_id', $data)) {
                MerchantPromocode::releaseTrashedCode(
                    array_key_exists('shop_id', $data) ? $data['shop_id'] : $promocode->shop_id,
                    $data['code'] ?? $promocode->code
                );
            }

            $promocode->update($data);

            if (isset($args['binding_type']) || isset($args['product_ids']) || isset($args['category_ids'])) {
                $this->syncBindings($promocode, $args);
                // Touch для триггера нового updated event с актуальными products/categories в payload
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
        $bindingType = $args['binding_type'] ?? $promocode->binding_type;

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
     * Проверка доступа к промокоду.
     * Platform-wide промокоды доступны только администраторам.
     */
    protected function checkAccess(MerchantPromocode $promocode): void
    {
        $user = auth()->user();

        if ($user->isGlobalScope()) {
            return;
        }

        if ($promocode->isPlatformWide()) {
            throw new AccessDeniedHttpException('У вас нет доступа к platform-wide промокоду.');
        }

        $shopIds = $user->shops()->pluck('shop_id')->toArray();

        if (!in_array($promocode->shop_id, $shopIds)) {
            throw new AccessDeniedHttpException('У вас нет доступа к этому промокоду.');
        }
    }
}
