<?php

namespace App\GraphQL\Mutations\AttributeFamily;

use App\Models\AttributeFamily;
use Illuminate\Support\Facades\DB;

final class AttributeFamilyCreate
{
    public function __invoke($_, array $args)
    {
        return DB::transaction(function () use ($args) {
            $family = AttributeFamily::create([
                'title' => $args['title'],
                'code' => $args['code'] ?? null,
                'position' => $args['position'] ?? 0,
            ]);

            if (!empty($args['attributes'])) {
                foreach ($args['attributes'] as $item) {
                    $family->attributes()->attach($item['attribute_id'], [
                        'position' => $item['position'] ?? 0,
                        'is_required' => $item['is_required'] ?? false,
                        'is_configurable' => $item['is_configurable'] ?? false,
                    ]);
                }
            }

            return ['code' => 200, 'message' => 'OK'];
        });
    }
}
