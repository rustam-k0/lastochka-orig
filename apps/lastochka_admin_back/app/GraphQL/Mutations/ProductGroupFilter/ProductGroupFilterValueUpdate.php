<?php

namespace App\GraphQL\Mutations\ProductGroupFilter;

use App\Models\ProductGroupFilterValue;

final class ProductGroupFilterValueUpdate
{
    public function __invoke($_, array $args)
    {
        $value = ProductGroupFilterValue::findOrFail($args['id']);
        $value->update($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
