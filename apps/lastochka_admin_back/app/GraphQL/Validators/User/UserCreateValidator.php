<?php

namespace App\GraphQL\Validators\User;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Rules\PhoneUnique;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UserCreateValidator extends Validator
{
  use PartnerStaffRules, StaffEmailRules;

  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'name' => ['required', 'max:255'],
      'surname' => ['required', 'max:255'],
      'patronymic' => ['nullable', 'max:255'],
      'phone' => ['bail', 'required', 'phone:AUTO', new PhoneUnique(new User())],
      // MID-293: обязательность почты регулируется настройкой staff_email_required
      // (Настройки → Общие → Сотрудники); по умолчанию выключена.
      'email' => $this->staffEmailRules(),
      'shops' => [Rule::requiredIf(function () {
        $role_id = $this->arg('role_id');
        if (!$role_id) {
          return false;
        }

        return in_array(Role::findById($role_id)?->name, [Role::COLLECTOR, Role::COURIER]);
      })],
      'role_id' => ['required', Rule::exists(Role::table, 'id'), $this->partnerRoleRule()],
      'shops.*' => ['required', Rule::exists((new Shop())->getTable(), 'id')->whereNull('deleted_at'), $this->partnerShopRule()],
      'password' => ['required', 'string', 'min:8', 'confirmed'],
      'password_confirmation' => ['required', 'same:password'],
      'send_credentials' => ['nullable', 'boolean'],
    ];
  }
}
