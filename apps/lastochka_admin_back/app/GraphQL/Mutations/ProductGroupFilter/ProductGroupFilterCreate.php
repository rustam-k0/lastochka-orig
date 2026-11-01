<?php

namespace App\GraphQL\Mutations\ProductGroupFilter;

use App\Models\ProductGroupFilter;

final class ProductGroupFilterCreate
{
    public function __invoke($_, array $args)
    {
        ProductGroupFilter::create($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
