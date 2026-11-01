<?php

namespace App\GraphQL\Mutations\ProductGroupFilter;

use App\Models\ProductGroupFilterValue;

final class ProductGroupFilterValueDelete
{
    public function __invoke($_, array $args)
    {
        ProductGroupFilterValue::destroy($args['id']);

        return ['code' => 200, 'message' => 'OK'];
    }
}
