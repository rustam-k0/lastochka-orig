<?php

namespace App\GraphQL\Validators\Category;

use App\Models\Category;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CategoryPublishValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id' => [
        'required',
        Rule::exists((new Category())->getTable(), 'id')->whereNull('deleted_at'),
      ],
    ];
  }
}
