<?php

namespace App\Rules;

use App\Models\Attribute;
use App\Models\Type;
use Illuminate\Contracts\Validation\Rule;

class ProductAttributeRule implements Rule
{
  private const SCALAR_VALUE_KEYS = ['string_value', 'text_value', 'int_value', 'float_value'];

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct()
  {
    //
  }

  /**
   * Determine if the validation rule passes.
   *
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {
    $exists = $this->checkExists($value);

    if (!$exists) {
      return true;
    }

    if (!$this->onlyUniqueAttributes($value)) {
      return false;
    }

    foreach ($value as $item) {
      if (!$this->checkAttributeCorrect($item)) {
        return false;
      }
    }

    return true;
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return "Атрибуты заполнены неверно";
  }

  private function checkExists(mixed $value): bool
  {

    if (!$value) {
      return false;
    }

    if (!is_array($value)) {
      return false;
    }

    if (!count($value)) {
      return false;
    }

    return true;
  }

  private function checkAttributeCorrect(mixed $item): bool
  {
    if (!isset($item["attribute_id"])) {
      return false;
    }

    $attribute = Attribute::find($item["attribute_id"]);

    if (!$attribute) {
      return false;
    }

    if ($attribute->type_id === Type::ATTRIBUTE_SELECT_ONE) {
      if (!isset($item['attribute_value_id'])) {
        return false;
      }

      return (bool)$attribute->attributeValues()->find($item['attribute_value_id']);
    }

    $resolved = $this->resolveScalarValue($item);

    if ($resolved === null) {
      return false;
    }

    if ($attribute->type_id === Type::ATTRIBUTE_STRING && is_string($resolved)) {
      return mb_strlen($resolved) <= 250;
    }

    return true;
  }

  /**
   * Возвращает первое непустое значение из string/text/int/float колонок,
   * чтобы валидатор допускал значения, пришедшие «не в ту» колонку —
   * мутация `ProductUpdate` их дальше нормализует по `type_id` атрибута.
   */
  private function resolveScalarValue(array $item): mixed
  {
    foreach (self::SCALAR_VALUE_KEYS as $key) {
      if (!array_key_exists($key, $item)) {
        continue;
      }

      $value = $item[$key];

      if ($value === null || $value === '') {
        continue;
      }

      return $value;
    }

    return null;
  }

  private function onlyUniqueAttributes(array $value):bool
  {
    $unique_count = collect($value)->unique("attribute_id")->count();
    return count($value) == $unique_count;
  }
}
