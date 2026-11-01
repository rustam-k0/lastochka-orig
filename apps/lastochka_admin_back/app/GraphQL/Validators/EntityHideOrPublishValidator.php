<?php

namespace App\GraphQL\Validators;

use App\Models\Shop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class EntityHideOrPublishValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $available = [
      (new Shop())->getTable(),
    ];

    $table = $this->arg('table');
    if (!in_array($table, $available)) {
      return [
        'table' => ['bail', 'required', Rule::in($available)],
      ];
    }

    return [
      'table' => ['bail', 'required', Rule::in($available)],
      'id' => ['bail', 'required', Rule::exists($table, 'id')->whereNull('deleted_at')],
    ];

  }
}
