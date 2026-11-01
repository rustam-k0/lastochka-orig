<?php

namespace App\GraphQL\Mutations\ProductGroupFilter;

use App\Models\ProductGroupFilter;

final class ProductGroupFilterUpdate
{
    public function __invoke($_, array $args)
    {
        $filter = ProductGroupFilter::findOrFail($args['id']);
        $filter->update($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
