<?php

namespace App\GraphQL\Mutations\ProductGroupFilter;

use App\Models\ProductGroupFilterValue;

final class ProductGroupFilterValueCreate
{
    public function __invoke($_, array $args)
    {
        ProductGroupFilterValue::create($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
