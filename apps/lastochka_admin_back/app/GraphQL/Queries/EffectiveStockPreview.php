<?php

namespace App\GraphQL\Queries;

use App\Services\Stock\StockOverrideService;

final class EffectiveStockPreview
{
    public function __construct(private StockOverrideService $stockOverrideService)
    {
    }

    public function __invoke($_, array $args): array
    {
        return $this->stockOverrideService->resolveForPreview(
            $args['category_ids'] ?? [],
            $args['shop_id'] ?? null,
            $args['unit_type_id'] ?? null
        );
    }
}
