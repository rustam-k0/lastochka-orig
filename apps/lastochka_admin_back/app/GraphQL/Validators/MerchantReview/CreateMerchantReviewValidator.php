<?php

namespace App\GraphQL\Validators\MerchantReview;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class CreateMerchantReviewValidator extends Validator
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
