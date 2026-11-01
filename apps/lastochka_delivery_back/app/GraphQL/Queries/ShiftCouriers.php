<?php

namespace App\GraphQL\Queries;

use App\Models\Role;
use App\Models\User;

final class ShiftCouriers
{
  public function __invoke($_, array $args)
  {
    $shift = \Auth::user()?->shift; // GlobalScope NotFinished — текущая смена

    if (!$shift) {
      return collect();
    }

    // Та же выборка, что в AssemblyFinish::notifyCouriersOrderReady.
    // Занятые курьеры остаются в списке (макет показывает их как «На заказе»,
    // выбор блокирует клиент) — счётчик активных назначений считается одним
    // подзапросом на всю выборку, чтобы User::isBusy не давал N+1.
    return User::role(Role::COURIER)
      ->whereHas('shift', fn($q) => $q->whereShopId($shift->shop_id))
      ->withCount(['deliveryOrders as active_delivery_orders_count' => fn($q) => $q->active()])
      ->get();
  }
}
