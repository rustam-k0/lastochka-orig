<?php

namespace App\GraphQL\Validators\Category;

use App\Models\Category;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CategoryChangePositionValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'items' => ['required', 'min:1'],
      'items.*.id' => ['required', Rule::exists((new Category())->getTable(), 'id')->whereNull('deleted_at')],
      'items.*.position' => ['required']
    ];
  }
}
