<?php

namespace App\GraphQL\Validators\OrderReview;

use App\Enums\OrderReviewErrorType;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class SetOrderReviewErrorTypeValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', Rule::exists('order_reviews', 'id')->whereNull('deleted_at')],
            'error_type' => ['nullable', 'string', Rule::in(OrderReviewErrorType::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.exists' => 'Отзыв не найден',
            'error_type.in' => 'Неизвестный тип ошибки',
        ];
    }
}
