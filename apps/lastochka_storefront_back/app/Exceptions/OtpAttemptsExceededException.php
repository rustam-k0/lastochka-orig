<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Все активные коды номера исчерпали лимит попыток ввода (OtpService::MAX_ATTEMPTS).
 */
final class OtpAttemptsExceededException extends InvalidOtpException
{
    public function __construct()
    {
        parent::__construct('Превышено число попыток ввода кода. Запросите новый код.');
    }
}
