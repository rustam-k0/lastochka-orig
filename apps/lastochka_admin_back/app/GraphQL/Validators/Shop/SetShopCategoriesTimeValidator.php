<?php

namespace App\GraphQL\Validators\Shop;

use App\Models\Category;
use App\Models\Shop;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class SetShopCategoriesTimeValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'shop_id'=>['required',Rule::exists((new Shop())->getTable(),'id')->whereNull('deleted_at')],
          'items.*.category_id'=>['required',Rule::exists((new Category())->getTable(),'id')->whereNull('deleted_at')],
          'items.*.time_to_take'=>['required','integer','min:0']
        ];
    }
}
