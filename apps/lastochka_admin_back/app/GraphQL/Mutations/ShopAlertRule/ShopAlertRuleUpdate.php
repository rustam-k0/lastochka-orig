<?php

namespace App\GraphQL\Mutations\ShopAlertRule;

use App\Models\ShopAlertRule;

final class ShopAlertRuleUpdate
{
    public function __invoke($_, array $args)
    {
        $rule = ShopAlertRule::findOrFail($args['id']);
        $rule->update($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
