<?php

namespace App\GraphQL\Validators\User;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UserUpdateValidator extends Validator
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
      'id' => ['required', Rule::exists(User::table, 'id')->whereNot('id', \Auth::id()), $this->partnerOwnStaffRule()],
      'name' => ['required', 'max:255'],
      'surname' => ['required', 'max:255'],
      'patronymic' => ['nullable', 'max:255'],
      'phone' => ['required', 'phone:AUTO', Rule::unique(User::table)->whereNull('deleted_at')->ignore($this->arg('id'))],
      // MID-293: обязательность почты — по настройке staff_email_required.
      // Уникальность — среди живых пользователей, кроме самого редактируемого (как у phone).
      'email' => $this->staffEmailRules($this->arg('id')),
      'role_id' => ['nullable', Rule::exists(Role::table, 'id'), $this->partnerRoleRule()],
      'shops' => [Rule::requiredIf(function () {
        $user_id = $this->arg('id');
        if (!$user_id || !$user = User::find($user_id)) {
          return false;
        }
        return in_array($user->roles?->first?->name, [Role::COLLECTOR, Role::COURIER]);
      })],
      // Привязку к магазинам партнёр на update не меняет (резолвер её игнорирует),
      // поэтому own-shop проверки тут нет — иначе сотрудник, привязанный к нескольким
      // магазинам, не прошёл бы валидацию.
      'shops.*' => ['required', Rule::exists((new Shop())->getTable(), 'id')->whereNull('deleted_at')],
      'password' => ['nullable', 'string', 'min:8', 'confirmed'],
      'password_confirmation' => ['required_with:password', 'same:password'],
      'send_credentials' => ['nullable', 'boolean'],
    ];
  }
}
