<?php

namespace App\GraphQL\Validators\Attribute;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class AttributeCreateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'title' => ['required', 'max:255'],
      'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/',
        // Код должен быть свободен среди АКТИВНЫХ атрибутов; коды удалённых
        // освобождаются хуком модели (ALADDIN-11991)
        Rule::unique('attributes', 'code')->whereNull('deleted_at'),
      ],
      'description' => ['nullable', 'string', 'max:5000'],
      'values' => ['nullable', 'array'],
      'values.*.title' => ['required', 'max:255']
    ];
  }

  public function messages(): array
  {
    return [
      'code.required' => 'Системный код обязателен',
      'code.regex' => 'Системный код должен начинаться с латинской буквы и содержать только a-z, 0-9 и _',
      'code.unique' => 'Атрибут с таким системным кодом уже существует',
    ];
  }
}
