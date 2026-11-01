<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Models\AggregatorQuarantine;

/**
 * Пометить запись карантина разрешённой (§8.4). Используется фронтом после
 * объединения карточек (double_match) — гасит запись и убирает из счётчика.
 */
final class ResolveQuarantine
{
    public function __invoke($_, array $args): array
    {
        $record = AggregatorQuarantine::findOrFail($args['id']);
        $record->update([
            'resolved_at' => now(),
            'resolved_by' => auth()->guard('api')->id(),
        ]);

        return ['code' => 200, 'message' => 'OK'];
    }
}
