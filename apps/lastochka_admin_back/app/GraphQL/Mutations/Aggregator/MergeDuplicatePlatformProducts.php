<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Aggregator\Services\MergePlatformProductsService;
use App\Models\PlatformProduct;

/**
 * Объединить дубли платформенных карточек (§6.9, §8.3.1). Одна операция для
 * карантина (double_match) и разовой чистки. Источник → архив (soft-delete).
 */
final class MergeDuplicatePlatformProducts
{
    public function __construct(private MergePlatformProductsService $service)
    {
    }

    public function __invoke($_, array $args): PlatformProduct
    {
        return $this->service->merge(
            (int) $args['targetId'],
            array_map('intval', $args['ids'])
        );
    }
}
