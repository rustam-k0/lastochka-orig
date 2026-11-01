<?php

namespace App\GraphQL\Queries\Aggregator;

use App\Models\AggregatorQuarantine;

/**
 * Счётчик неразрешённых записей карантина для бейджа в сайдбаре (§8.5).
 */
final class QuarantineCountQuery
{
    public function __invoke($_, array $args): int
    {
        return AggregatorQuarantine::query()->unresolved()->count();
    }
}
