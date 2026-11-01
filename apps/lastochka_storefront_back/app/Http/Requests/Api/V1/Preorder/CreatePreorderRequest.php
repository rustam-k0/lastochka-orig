<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Preorder;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePreorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** @example Иван Иванов */
            'fullName' => ['required', 'string', 'max:255'],
            /** @example +79991234567 */
            'phone' => ['required', 'string', 'max:20'],
            /** @example ivan@example.com */
            'email' => ['nullable', 'email', 'max:255'],
            /** @example 2 */
            'quantity' => ['required', 'integer', 'min:1'],
            /** @example Нужно к понедельнику */
            'comment' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'fullName.required' => 'Укажите ФИО',
            'phone.required' => 'Укажите телефон',
            'quantity.required' => 'Укажите количество',
            'quantity.min' => 'Количество должно быть не менее 1',
        ];
    }
}
