<?php

namespace App\GraphQL\Queries;

use App\Models\Order;

final class CourierAssignmentEnabled
{
  public function __invoke($_, array $args): bool
  {
    return Order::isCourierAssignmentModuleEnabled();
  }
}
