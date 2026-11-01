<?php

namespace App\Rules;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\Rule;

class AdminLoginRule implements Rule
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
  public function passes($attribute, $value): bool
  {
    $admin = User::whereEmail($value)->first();

    if (\App\Models\AdminSetting::getValue('role_access_matrix_enabled') === '1') {
      return $admin->roles->whereIn('scope_type', [Role::SCOPE_GLOBAL, Role::SCOPE_SHOP])->isNotEmpty();
    }

    return $admin->hasAnyRole(Role::ADMIN, Role::API, Role::SHOP);
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'Вход только для админа';
  }
}
