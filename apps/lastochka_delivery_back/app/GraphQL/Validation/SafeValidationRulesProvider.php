<?php

namespace App\GraphQL\Validation;

use GraphQL\Validator\Rules\ValuesOfCorrectType;
use Nuwave\Lighthouse\Execution\ValidationRulesProvider;

/**
 * Подменяет штатное правило ValuesOfCorrectType на устойчивое к неизвестным аргументам.
 *
 * @see SafeValuesOfCorrectType
 */
class SafeValidationRulesProvider extends ValidationRulesProvider
{
  public function validationRules(): ?array
  {
    $rules = parent::validationRules();

    if ($rules === null) {
      return null;
    }

    $rules[ValuesOfCorrectType::class] = new SafeValuesOfCorrectType();

    return $rules;
  }
}
