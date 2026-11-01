<?php

namespace App\GraphQL\Validators\ProductReview;

use App\Models\Status;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CreateProductReviewValidator extends Validator
{
    /**
     * Return the validation rules.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'input.shop_id' => ['required', Rule::exists('shops', 'id')],
            'input.product_id' => [
                'required',
                Rule::exists('products', 'id')
                    ->where('shop_id', $this->arg('input.shop_id'))
                    ->where('status_id', Status::CODE_ACTIVE)
                    ->whereNull('deleted_at'),
            ],
            'input.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'input.description' => ['nullable', 'string', 'max:2000'],
            'input.is_published' => ['nullable', 'boolean'],
            'input.author_name' => ['nullable', 'string', 'max:255'],
            'input.author_phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Return custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'input.shop_id.required' => 'ID магазина обязателен',
            'input.shop_id.exists' => 'Указанный магазин не найден',
            'input.product_id.required' => 'ID товара обязателен',
            'input.product_id.exists' => 'Товар не найден, не опубликован или не принадлежит выбранному магазину',
            'input.rating.required' => 'Оценка обязательна',
            'input.rating.integer' => 'Оценка должна быть целым числом',
            'input.rating.min' => 'Оценка не может быть меньше 1',
            'input.rating.max' => 'Оценка не может быть больше 5',
            'input.description.max' => 'Текст отзыва не должен превышать 2000 символов',
            'input.author_name.max' => 'Имя автора не должно превышать 255 символов',
            'input.author_phone.max' => 'Телефон автора не должен превышать 20 символов',
        ];
    }
}
