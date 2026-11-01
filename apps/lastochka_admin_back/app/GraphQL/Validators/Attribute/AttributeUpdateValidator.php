<?php

namespace App\GraphQL\Validators\Attribute;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Rules\NotDefaultAttribute;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class AttributeUpdateValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      "id" => ["required", Rule::exists((new Attribute())->getTable(), "id")->whereNull("deleted_at")],
      "title" => ["required", "max:255"],
      "code" => [
        "required",
        "string",
        "max:100",
        "regex:/^[a-z][a-z0-9_]*$/",
        Rule::unique((new Attribute())->getTable(), "code")->ignore($this->arg("id"))->whereNull("deleted_at"),
      ],
      "description" => ["nullable", "string", "max:5000"],
      "values" => ["nullable", "array"],
      "values.*.title" => ["required", "max:255"],
      "values.*.id"=>["nullable",Rule::exists((new AttributeValue())->getTable(),"id")->whereNull("deleted_at")]
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
