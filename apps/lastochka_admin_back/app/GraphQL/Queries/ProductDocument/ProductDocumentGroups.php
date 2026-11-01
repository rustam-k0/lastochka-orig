<?php

namespace App\GraphQL\Queries\ProductDocument;

use App\Models\ProductDocument;

final class ProductDocumentGroups
{
    public function __invoke($_, array $args): array
    {
        return ProductDocument::whereNotNull('group')
            ->where('group', '!=', '')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->toArray();
    }
}
