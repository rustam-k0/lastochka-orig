<?php

namespace App\GraphQL\Validators\Delivery;

use App\Models\Status;
use App\Rules\CourierHasOrderRule;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class DeliveryArrivedValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'id'=>['bail','required',new CourierHasOrderRule(Status::DELIVERY_IN_PROGRESS)]
    ];
  }
}
