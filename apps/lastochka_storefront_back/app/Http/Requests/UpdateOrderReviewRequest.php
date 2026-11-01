<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\OrderReview;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateOrderReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var Order $order */
        $order = $this->route('order');
        $review = $order->review;

        // Если отзыва нет, разрешаем (проверку существования делаем в контроллере для 404)
        if (!$review) {
            return true;
        }

        return $this->user()->can('update', $review);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Поле "Рейтинг" обязательно для заполнения',
            'rating.integer' => 'Рейтинг должен быть числом',
            'rating.min' => 'Рейтинг не может быть меньше 1',
            'rating.max' => 'Рейтинг не может быть больше 5',
            'description.max' => 'Описание не может быть длиннее 1000 символов',
        ];
    }
}
