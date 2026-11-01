<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Estimate;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите название сметы',
            'name.max' => 'Название сметы не должно превышать 255 символов',
            'description.max' => 'Описание не должно превышать 200 символов',
        ];
    }
}
