<?php

namespace App\GraphQL\Validators\Product;

use App\Models\Supplement;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class BulkAssignSupplementsValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'product_ids'                          => ['required', 'array', 'min:1'],
            'product_ids.*'                        => ['integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'supplement.title'                     => ['required', 'max:255'],
            'supplement.type'                      => ['required', Rule::in([Supplement::TYPE_ONE, Supplement::TYPE_MANY])],
            'supplement.is_required'               => ['nullable', 'boolean'],
            'supplement.values'                    => ['required', 'array'],
            'supplement.values.*.title'            => ['required', 'max:255'],
            'supplement.values.*.price'            => ['nullable', 'gte:0', 'required_without:supplement.values.*.product_id'],
            'supplement.values.*.product_id'       => ['nullable', 'integer'],
            'supplement.values.*.max_quantity'     => ['nullable', 'integer', 'gte:0'],
            'supplement.values.*.track_stock'      => ['nullable', 'boolean'],
            'supplement.values.*.position'         => ['nullable', 'integer', 'gte:0'],
        ];
    }
}
