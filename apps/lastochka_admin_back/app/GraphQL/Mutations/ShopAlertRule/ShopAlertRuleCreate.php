<?php

namespace App\GraphQL\Mutations\ShopAlertRule;

use App\Models\ShopAlertRule;

final class ShopAlertRuleCreate
{
    public function __invoke($_, array $args)
    {
        ShopAlertRule::create($args);

        return ['code' => 200, 'message' => 'OK'];
    }
}
