<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Review;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Оценка от 1 до 5.
             *
             * @example 4
             */
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            /**
             * Текст отзыва.
             *
             * @example Обновлённый отзыв после повторного заказа.
             */
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rating.required' => 'Укажите оценку',
            'rating.integer' => 'Оценка должна быть числом',
            'rating.min' => 'Минимальная оценка — 1',
            'rating.max' => 'Максимальная оценка — 5',
            'description.max' => 'Текст отзыва не должен превышать 2000 символов',
        ];
    }
}
