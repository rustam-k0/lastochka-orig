<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Проверяет, что строка является EditorJS-документом:
 * валидный JSON-объект с непустым массивом blocks.
 */
class EditorJsDocument implements Rule
{
    /**
     * @param string $attribute
     * @param mixed $value
     */
    public function passes($attribute, $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded) || !isset($decoded['blocks']) || !is_array($decoded['blocks'])) {
            return false;
        }

        return count($decoded['blocks']) > 0;
    }

    public function message(): string
    {
        return 'Содержимое страницы обязательно: добавьте хотя бы один блок текста';
    }
}
