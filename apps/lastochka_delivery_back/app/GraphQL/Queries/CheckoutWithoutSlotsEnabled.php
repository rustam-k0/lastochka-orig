<?php

namespace App\GraphQL\Queries;

use App\Models\Order;

final class CheckoutWithoutSlotsEnabled
{
  public function __invoke($_, array $args): bool
  {
    return Order::isCheckoutWithoutSlotsModuleEnabled();
  }
}
