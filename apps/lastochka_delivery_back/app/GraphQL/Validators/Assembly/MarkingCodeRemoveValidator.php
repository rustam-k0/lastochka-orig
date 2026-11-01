<?php

namespace App\GraphQL\Validators\Assembly;

use App\Rules\AssemblyItemExistsRule;
use Nuwave\Lighthouse\Validation\Validator;

final class MarkingCodeRemoveValidator extends Validator
{
  /**
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'assembly_item_id' => ['required', 'integer', 'bail', new AssemblyItemExistsRule(allowAssembled: true)],
      'code' => ['required', 'string', 'max:255'],
    ];
  }
}
