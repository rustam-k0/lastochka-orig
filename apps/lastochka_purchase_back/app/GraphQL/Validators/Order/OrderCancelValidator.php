<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Order;
use App\Models\OrderCancelReason;
use App\Models\Status;
use App\Rules\OrderCanBeCanceled;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderCancelValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    $rules = [
      'order_id' => ['bail', 'required', Rule::exists((new Order())->getTable(), 'id')
        ->whereNot('status_id',Status::ORDER_FINISHED)
        ->whereNot('status_id',Status::ORDER_CANCELED),
        new OrderCanBeCanceled()
      ],
      'reason_id' => ['nullable', 'integer', Rule::exists((new OrderCancelReason())->getTable(), 'id')->where('is_active', true)],
      'custom_reason' => ['nullable', 'string', 'max:1000'],
    ];

    // Причина обязательна только для сотрудника (guard api), не для клиента
    // (guard client, самоотмена в сторфронте) — withValidator() здесь не
    // применим, эта версия Lighthouse его не поддерживает (см. Global Constraints).
    if (auth()->guard('api')->check()
      && empty($this->arg('reason_id'))
      && empty(trim((string) $this->arg('custom_reason')))
    ) {
      $rules['reason_id'][] = 'required';
    }

    return $rules;
  }
}
