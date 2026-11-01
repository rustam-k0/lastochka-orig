<?php

namespace App\GraphQL\Queries;

use App\Models\AttributeFamily;
use Illuminate\Support\Collection;

final class AttributeFamilyAttributesResolver
{
    public function __invoke(AttributeFamily $family, array $args): Collection
    {
        return $family->attributes()->get()->map(function ($attr) {
            return (object) [
                'id' => $attr->pivot->id,
                'attribute' => $attr,
                'position' => $attr->pivot->position,
                'is_required' => (bool) $attr->pivot->is_required,
                'is_configurable' => (bool) $attr->pivot->is_configurable,
            ];
        });
    }
}
