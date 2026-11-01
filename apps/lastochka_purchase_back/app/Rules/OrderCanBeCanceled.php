<?php

namespace App\Rules;

use App\Models\AdminSetting;
use App\Models\Order;
use App\Models\Role;
use App\Support\OrderActionAccess;
use Illuminate\Contracts\Validation\Rule;

class OrderCanBeCanceled implements Rule
{
  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct()
  {
    //
  }

  /**
   * Determine if the validation rule passes.
   *
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {

    $client = auth()->guard('client')->user();

    $admin = auth()->guard('api')->user();

    $order = Order::find($value);

    if ($client) {
      return $order->client_id == $client->id;
    }

    if ($admin) {
      // При включённой матрице — по праву orders.cancel (байпас admin/superadmin),
      // при выключенной — старое поведение (только admin)
      if (AdminSetting::getValue('role_access_matrix_enabled') === '1') {
        return OrderActionAccess::allowedUnderMatrix($admin, 'orders.cancel');
      }

      return $admin->hasRole(Role::ADMIN);
    }

    return false;
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'У вас нет прав доступа для отмены данного заказа';
  }
}
