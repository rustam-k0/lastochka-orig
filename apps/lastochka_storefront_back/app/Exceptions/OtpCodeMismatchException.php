<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Введённый код не совпал ни с одним из активных кодов номера.
 */
final class OtpCodeMismatchException extends InvalidOtpException
{
    public function __construct()
    {
        parent::__construct('Неверный код подтверждения.');
    }
}
