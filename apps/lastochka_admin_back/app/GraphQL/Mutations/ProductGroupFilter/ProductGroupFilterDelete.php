<?php

namespace App\GraphQL\Mutations\ProductGroupFilter;

use App\Models\ProductGroupFilter;

final class ProductGroupFilterDelete
{
    public function __invoke($_, array $args)
    {
        ProductGroupFilter::destroy($args['id']);

        return ['code' => 200, 'message' => 'OK'];
    }
}
