<?php

namespace App\GraphQL\Queries\Attribute;

use App\Models\Attribute;

final class AttributeIsDefault
{
    public function __invoke(Attribute $attribute): bool
    {
        return $attribute->defaultAttribute()->exists();
    }
}
