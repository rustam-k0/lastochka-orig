<?php

namespace App\GraphQL\Mutations\ShopAlertRule;

use App\Models\ShopAlertRule;

final class ShopAlertRuleDelete
{
    public function __invoke($_, array $args)
    {
        ShopAlertRule::findOrFail($args['id'])->delete();

        return ['code' => 200, 'message' => 'OK'];
    }
}
