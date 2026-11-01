<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Для номера нет ни одного активного OTP-кода: все либо истекли, либо уже
 * использованы (в т.ч. вышли за пределы окна последних активных кодов).
 */
final class OtpExpiredOrUsedException extends InvalidOtpException
{
    public function __construct()
    {
        parent::__construct('Код подтверждения устарел или уже использован. Запросите новый код.');
    }
}
