<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Payment\ReturnUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Адрес возврата после оплаты: относительный путь витрины, абсолютный адрес
 * с разрешённого домена либо диплинк приложения с разрешённой схемой.
 *
 * Отказ отдаём явной ошибкой валидации, а не тихой подменой на дефолт:
 * клиент сразу видит, что его домен не в белом списке, вместо возврата
 * «куда-то не туда» уже после оплаты.
 *
 * @see ReturnUrl
 */
final class ReturnUrlRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('Адрес возврата должен быть строкой.');

            return;
        }

        if (mb_strlen($value) > ReturnUrl::MAX_LENGTH) {
            $fail('Адрес возврата длиннее '.ReturnUrl::MAX_LENGTH.' символов.');

            return;
        }

        if (! ReturnUrl::isAllowed($value)) {
            $fail('Недопустимый адрес возврата: домен не входит в список разрешённых.');
        }
    }
}
