<?php

namespace App\GraphQL\Mutations\AttributeFamily;

use App\Models\AttributeFamily;
use Illuminate\Support\Facades\DB;

final class AttributeFamilyDelete
{
    public function __invoke($_, array $args)
    {
        $family = AttributeFamily::findOrFail($args['id']);

        if ($family->is_system) {
            return ['code' => 403, 'message' => 'Системное семейство нельзя удалить'];
        }

        return DB::transaction(function () use ($family) {
            $family->categories()->update(['attribute_family_id' => null]);
            $family->delete();

            return ['code' => 200, 'message' => 'OK'];
        });
    }
}
