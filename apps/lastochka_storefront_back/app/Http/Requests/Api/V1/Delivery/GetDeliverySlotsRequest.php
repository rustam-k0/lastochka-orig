<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GetDeliverySlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * ID адреса доставки (должен иметь координаты).
             *
             * @example 5
             */
            'addressId' => [
                'required',
                'integer',
                Rule::exists('addresses', 'id')->where('user_id', $this->user()->id),
            ],
            /**
             * Дата для получения слотов (по умолчанию сегодня).
             *
             * @example 2024-01-20
             */
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'addressId.required' => 'Укажите адрес доставки',
            'addressId.exists' => 'Адрес не найден',
            'date.date_format' => 'Неверный формат даты. Ожидается: YYYY-MM-DD',
        ];
    }

    public function attributes(): array
    {
        return [
            'addressId' => 'адрес',
            'date' => 'дата',
        ];
    }

    public function resolvedDate(): string
    {
        return $this->validated('date') ?? now()->format('Y-m-d');
    }
}
