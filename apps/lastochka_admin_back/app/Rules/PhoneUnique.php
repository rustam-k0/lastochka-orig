<?php

namespace App\Rules;

use App\Services\PhoneFormatter;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;

class PhoneUnique implements Rule
{
  private $model;

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct(Model $model)
  {
    $this->model = $model;
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
    $phone = PhoneFormatter::format($value);

    return !$this->model->where('phone', $phone)->exists();
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return "Такое значение поля Номер телефона уже существует";
  }
}
