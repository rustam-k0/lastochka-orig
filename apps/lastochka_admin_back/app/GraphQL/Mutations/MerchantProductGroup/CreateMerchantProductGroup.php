<?php

namespace App\GraphQL\Mutations\MerchantProductGroup;

use App\Enums\ProductGroupFillType;
use App\Models\MerchantProductGroup;
use App\Services\MerchantMultiShop\MultiShopCreateService;
use App\Services\MerchantProductGroupService;
use Illuminate\Support\Facades\DB;

final class CreateMerchantProductGroup
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantProductGroup
    {
        $args = $this->sanitizeInput($args);
        $args = \App\Services\CustomCheckboxService::sanitizeArgs('MerchantProductGroup', $args);

        $targets = MultiShopCreateService::resolveTargets($args);

        // Проверяем что магазин определён после санитизации
        if (empty($targets['primary'])) {
            throw new \InvalidArgumentException('ID магазина не определён. У пользователя нет привязанного магазина.');
        }

        $group = DB::transaction(function () use ($args, $targets) {
            return (new MerchantProductGroupService())->createForShop($args, $targets['primary']);
        });

        // Копии в остальные магазины — фоновой джобой (MID-229)
        MultiShopCreateService::dispatchCopies(
            \App\Jobs\MerchantMultiShop\CreateMerchantEntityCopiesJob::ENTITY_PRODUCT_GROUP,
            $args,
            $targets,
        );

        return $group->fresh(['image', 'shop', 'products']);
    }

    /**
     * Санитизация входных данных в зависимости от роли пользователя
     */
    protected function sanitizeInput(array $args): array
    {
        $user = auth()->user();

        // Для роли SHOP (seller) — автоматически подставляем первый магазин
        if ($user->isShopScope()) {
            $userShop = $user->shops()->first();
            if ($userShop) {
                $args['shop_id'] = $userShop->shop_id;
            }

            // Мультимагазинное создание доступно только глобальным ролям
            unset($args['shop_ids'], $args['all_shops']);
        }

        // Не-админы не могут управлять show_on_main
        if (!$user->isGlobalScope()) {
            $args['show_on_main'] = false;
        }

        // Автоматическую группу наполняет витрина по критерию: выбранные товары
        // не принимаются ни в основной группе, ни в копиях по магазинам (MID-229)
        if (!ProductGroupFillType::isManualValue($args['fill_type'] ?? ProductGroupFillType::MANUAL->value)) {
            unset($args['product_ids'], $args['products_with_qty']);
        }

        return $args;
    }
}
