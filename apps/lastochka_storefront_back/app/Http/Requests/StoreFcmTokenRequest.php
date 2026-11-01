<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFcmTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Принимаем deprecated snake_case поля как алиасы camelCase для обратной совместимости.
     */
    protected function prepareForValidation(): void
    {
        foreach (['device_type' => 'deviceType', 'device_name' => 'deviceName'] as $snake => $camel) {
            if ($this->has($snake) && ! $this->has($camel)) {
                $this->merge([$camel => $this->input($snake)]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'deviceType' => ['nullable', 'string', 'in:ios,android'],
            'deviceName' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'FCM токен обязателен для заполнения.',
            'token.string' => 'FCM токен должен быть строкой.',
            'token.max' => 'FCM токен не должен превышать 255 символов.',
            'deviceType.in' => 'Тип устройства должен быть ios или android.',
            'deviceName.max' => 'Название устройства не должно превышать 255 символов.',
        ];
    }
}