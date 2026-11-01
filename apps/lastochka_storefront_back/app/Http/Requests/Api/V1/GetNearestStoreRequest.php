<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class GetNearestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'cityId' => ['sometimes', 'integer', 'min:1'],
            'radius' => ['sometimes', 'numeric', 'gt:0'],
            'shippingMethod' => ['sometimes', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required' => 'Не переданы координаты пользователя',
            'longitude.required' => 'Не переданы координаты пользователя',
        ];
    }
}
