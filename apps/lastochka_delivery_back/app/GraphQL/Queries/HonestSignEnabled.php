<?php

namespace App\GraphQL\Queries;

use App\Models\Order;

final class HonestSignEnabled
{
  public function __invoke($_, array $args): bool
  {
    return Order::isHonestSignModuleEnabled();
  }
}
