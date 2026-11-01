<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Status;
use App\Rules\CourierHasOrderRule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderItemReturnByCourierValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'delivery_order_id' => ['bail', 'required', new CourierHasOrderRule([Status::DELIVERY_IN_PROGRESS, Status::DELIVERY_ARRIVED])],
            'order_item_id' => ['bail', 'required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'reason_id' => ['nullable', 'integer', 'exists:product_cancel_reasons,id'],
        ];
    }
}
