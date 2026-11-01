<?php

namespace App\GraphQL\Mutations\AttributeFamily;

use App\Models\AttributeFamily;
use Illuminate\Support\Facades\DB;

final class AttributeFamilyUpdate
{
    public function __invoke($_, array $args)
    {
        $family = AttributeFamily::findOrFail($args['id']);

        return DB::transaction(function () use ($family, $args) {
            $family->update([
                'title' => $args['title'] ?? $family->title,
                'code' => array_key_exists('code', $args) ? $args['code'] : $family->code,
                'position' => $args['position'] ?? $family->position,
            ]);

            if (isset($args['attributes'])) {
                $syncData = [];
                foreach ($args['attributes'] as $item) {
                    $syncData[$item['attribute_id']] = [
                        'position' => $item['position'] ?? 0,
                        'is_required' => $item['is_required'] ?? false,
                        'is_configurable' => $item['is_configurable'] ?? false,
                    ];
                }
                $family->attributes()->sync($syncData);
            }

            return ['code' => 200, 'message' => 'OK'];
        });
    }
}
