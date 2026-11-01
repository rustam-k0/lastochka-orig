<?php

namespace App\GraphQL\Validators\Order;

use App\Models\Order;
use App\Models\Status;
use App\Rules\UserInShop;
use App\Rules\IsCollector;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class OrderCollectorUpdateValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'id'=>['required',Rule::exists((new Order())->getTable())
              ->where('status_id',Status::ORDER_PREPARING)
              ->whereNull('deleted_at')
            ],
            'collector_id'=>['required',new IsCollector(),new UserInShop($this->arg('id'))],
        ];
    }
}
