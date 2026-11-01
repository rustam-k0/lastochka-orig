<?php

declare(strict_types=1);

namespace App\GraphQL\Mutations\ConfigurableProduct;

use App\Models\Product;
use App\Services\ConfigurableProductService;
use App\Services\ModuleConfigService;
use Illuminate\Support\Facades\DB;

final class ConfigurableProductDeleteVariant
{
    public function __construct(private ConfigurableProductService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        if (!ModuleConfigService::isConfigurableProductsEnabled()) {
            return ['code' => 403, 'message' => 'Фича «Вариативные товары» отключена'];
        }

        $variant = Product::whereNotNull('parent_id')->find($args['variant_id']);

        if (!$variant) {
            return ['code' => 404, 'message' => 'Вариант не найден'];
        }

        if (!\Auth::user()->isGlobalScope() && $variant->shop_id !== \Auth::user()->shops()->first()?->shop_id) {
            return ['code' => 403, 'message' => 'Нет доступа'];
        }

        try {
            $this->service->ensureNoActiveOrders($variant);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['code' => 422, 'message' => implode('; ', array_merge(...array_values($e->errors())))];
        }

        $parentId = (int) $variant->parent_id;
        $variantId = (int) $variant->id;

        $isLastVariant = DB::transaction(function () use ($variant, $parentId, $variantId) {
            $variant->delete();

            $this->service->logChange(
                $variantId,
                'delete_variant',
                [
                    'variant_id' => $variantId,
                    'parent_id' => $parentId,
                ]
            );

            event(new \App\Events\ProductUpdated($variant));

            return Product::where('parent_id', $parentId)->count() === 0;
        });

        $message = 'OK';
        if ($isLastVariant) {
            $message = 'Это был последний вариант parent #' . $parentId . '. Parent остался без variants.';
        }

        return ['code' => 200, 'message' => $message];
    }
}
