<?php

namespace App\GraphQL\Validators\User;

use App\Models\User;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class UserDeleteValidator extends Validator
{
  use PartnerStaffRules;

  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => ['required', Rule::exists(User::table)->whereNot('id', \Auth::id()), $this->partnerOwnStaffRule()]
    ];
  }
}
