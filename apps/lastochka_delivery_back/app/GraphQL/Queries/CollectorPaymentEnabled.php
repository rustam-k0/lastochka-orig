<?php

namespace App\GraphQL\Queries;

use App\Models\Order;

final class CollectorPaymentEnabled
{
  public function __invoke($_, array $args): bool
  {
    return Order::isCollectorPaymentModuleEnabled();
  }
}
