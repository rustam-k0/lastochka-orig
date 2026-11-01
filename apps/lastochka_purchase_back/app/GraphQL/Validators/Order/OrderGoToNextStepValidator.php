<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Order;
use App\Models\Status;
use App\Models\User;
use App\Rules\OrderCanBeChanged;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderGoToNextStepValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $statuses = [
      Status::ORDER_CREATED,
      Status::ORDER_PREPARING,
      Status::ORDER_ASSEMBLED,
      Status::ORDER_IN_DELIVERY,
    ];

    return [
      'id' => [
        'required',
        Rule::exists((new Order())->getTable())->whereNull('deleted_at')
          ->whereIn('status_id', $statuses),
        new OrderCanBeChanged($this->arg('collector_id'), $this->arg('courier_id'))
      ],
      'collector_id' => ['bail', 'nullable', Rule::exists((new User())->getTable(), 'id')->whereNull('deleted_at'),],
      'courier_id' => ['bail', 'nullable', Rule::exists((new User())->getTable(), 'id')->whereNull('deleted_at'),],
    ];
  }
}
