<?php

namespace App\GraphQL\Queries;

use App\Models\AttributeFamily;
use App\Models\Category;
use Illuminate\Support\Collection;

final class CategoryFamilyAttributes
{
    public function __invoke($_, array $args): Collection
    {
        $categoryId = $args['category_id'];
        $category = Category::find($categoryId);

        $result = collect();

        // 1. System families — always included
        $systemFamilies = AttributeFamily::where('is_system', true)->get();
        foreach ($systemFamilies as $family) {
            $pivots = $family->attributes()->get();
            foreach ($pivots as $attr) {
                $result->push((object) [
                    'id' => $attr->pivot->id,
                    'attribute' => $attr,
                    'position' => $attr->pivot->position,
                    'is_required' => (bool) $attr->pivot->is_required,
                    'is_configurable' => (bool) $attr->pivot->is_configurable,
                ]);
            }
        }

        // 2. Category's family (if assigned)
        if ($category && $category->attribute_family_id) {
            $family = AttributeFamily::find($category->attribute_family_id);
            if ($family && !$family->is_system) {
                $pivots = $family->attributes()->get();
                foreach ($pivots as $attr) {
                    $result->push((object) [
                        'id' => $attr->pivot->id,
                        'attribute' => $attr,
                        'position' => $attr->pivot->position,
                        'is_required' => (bool) $attr->pivot->is_required,
                        'is_configurable' => (bool) $attr->pivot->is_configurable,
                    ]);
                }
            }
        }

        return $result;
    }
}
