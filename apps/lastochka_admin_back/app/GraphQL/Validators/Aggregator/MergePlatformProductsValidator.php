<?php

namespace App\GraphQL\Validators\Aggregator;

use Nuwave\Lighthouse\Validation\Validator;

/**
 * Валидация объединения дублей платформенных карточек (§8.3.1).
 */
final class MergePlatformProductsValidator extends Validator
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:platform_products,id'],
            'targetId' => ['required', 'integer', 'exists:platform_products,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Не указаны карточки-источники',
            'ids.*.exists' => 'Карточка-источник не найдена',
            'targetId.required' => 'Не указана целевая карточка',
            'targetId.exists' => 'Целевая карточка не найдена',
        ];
    }
}
