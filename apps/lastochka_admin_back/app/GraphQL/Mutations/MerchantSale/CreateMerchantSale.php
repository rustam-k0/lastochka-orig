<?php

namespace App\GraphQL\Mutations\MerchantSale;

use App\Models\MerchantSale;
use App\Services\MerchantMultiShop\MultiShopCreateService;
use App\Services\MerchantSaleService;
use Illuminate\Support\Facades\DB;

final class CreateMerchantSale
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): MerchantSale
    {
        $args = \App\Services\CustomCheckboxService::sanitizeArgs('MerchantSale', $args);

        // Мультимагазинное создание (shop_ids / all_shops) доступно только
        // глобальным ролям — shop-scope создаёт строго в своём магазине.
        if (auth()->user()->isShopScope()) {
            unset($args['shop_ids'], $args['all_shops']);
        }

        $targets = MultiShopCreateService::resolveTargets($args);

        if (empty($targets['primary'])) {
            throw new \InvalidArgumentException('Магазин не определён: передайте shop_id, shop_ids или all_shops.');
        }

        $sale = DB::transaction(function () use ($args, $targets) {
            return (new MerchantSaleService())->createForShop($args, $targets['primary']);
        });

        // Копии в остальные магазины — фоновой джобой (MID-237)
        MultiShopCreateService::dispatchCopies(
            \App\Jobs\MerchantMultiShop\CreateMerchantEntityCopiesJob::ENTITY_SALE,
            $args,
            $targets,
        );

        return $sale->fresh(['image', 'shop', 'products']);
    }
}
