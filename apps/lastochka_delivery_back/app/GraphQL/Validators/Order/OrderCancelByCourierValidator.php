<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Status;
use App\Rules\CourierHasOrderRule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderCancelByCourierValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'delivery_order_id' => ['bail', 'required', new CourierHasOrderRule([Status::DELIVERY_IN_PROGRESS, Status::DELIVERY_ARRIVED])],
        ];
    }
}
