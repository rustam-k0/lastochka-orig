<?php

namespace App\GraphQL\Mutations\Brand;

use App\Models\Brand;

final class BrandChangePosition
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Brand
    {
        $brand = Brand::findOrFail($args['id']);
        $brand->update(['position' => $args['position']]);
        return $brand->fresh(['logoImage']);
    }
}
