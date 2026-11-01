<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Aggregator\Services\MergePlatformProductsService;

/**
 * Превью объединения дублей платформенных карточек (§8.3.1).
 */
final class PreviewMergePlatformProducts
{
    public function __construct(private MergePlatformProductsService $service)
    {
    }

    public function __invoke($_, array $args): array
    {
        return $this->service->preview(
            (int) $args['targetId'],
            array_map('intval', $args['ids'])
        );
    }
}
