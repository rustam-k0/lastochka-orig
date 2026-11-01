<?php

namespace App\GraphQL\Queries;

use App\Models\Order;

final class HeavyOrdersEnabled
{
  public function __invoke($_, array $args): bool
  {
    return Order::isHeavyOrdersModuleEnabled();
  }
}
