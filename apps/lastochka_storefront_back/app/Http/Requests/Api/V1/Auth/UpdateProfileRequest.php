<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Имя пользователя.
             *
             * @example Иван
             */
            'firstName' => ['nullable', 'string', 'max:100'],
            /**
             * Фамилия пользователя.
             *
             * @example Иванов
             */
            'lastName' => ['nullable', 'string', 'max:100'],
            /**
             * Отчество пользователя.
             *
             * @example Иванович
             */
            'patronymic' => ['nullable', 'string', 'max:100'],
            /**
             * Email адрес.
             *
             * @example ivan@example.com
             */
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            /**
             * Дата рождения в формате ISO 8601.
             *
             * @example 1990-01-15
             */
            'birthDate' => ['nullable', 'date', 'before_or_equal:today'],
            /**
             * Пол: male, female или other.
             *
             * @example male
             */
            'gender' => ['nullable', new Enum(Gender::class)],
            /**
             * Флаг подавления модалки готовности товара.
             *
             * @example false
             */
            'readyTimeModalSuppressed' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'firstName.max' => 'Имя слишком длинное',
            'lastName.max' => 'Фамилия слишком длинная',
            'email.email' => 'Неверный формат email',
            'email.unique' => 'Этот email уже используется',
            'birthDate.date' => 'Неверный формат даты рождения',
            'birthDate.before_or_equal' => 'Дата рождения не может быть в будущем',
            'gender.Illuminate\Validation\Rules\Enum' => 'Неверное значение пола',
        ];
    }

    public function attributes(): array
    {
        return [
            'firstName' => 'имя',
            'lastName' => 'фамилия',
            'email' => 'email',
            'birthDate' => 'дата рождения',
            'gender' => 'пол',
        ];
    }
}
