<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\ProductIssueReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateProductIssueReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** @example user@example.com */
            'email' => ['required', 'email', 'max:255'],
            /** @example Неверное описание характеристик */
            'message' => ['required', 'string', 'max:200'],
            /** @example description_error */
            'type' => ['required', 'string', Rule::in(config('product-issues.types', ['description_error']))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Укажите email',
            'email.email' => 'Некорректный формат email',
            'message.required' => 'Опишите проблему',
            'message.max' => 'Максимальная длина сообщения — 200 символов',
            'type.required' => 'Укажите тип обращения',
            'type.in' => 'Неизвестный тип обращения',
        ];
    }
}
