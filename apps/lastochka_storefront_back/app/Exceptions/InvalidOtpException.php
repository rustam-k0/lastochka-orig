<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * Базовое исключение отказа при проверке OTP-кода (phone-confirm / change-phone/confirm).
 *
 * Наследники различают причину отказа собственным текстом (устарел/использован,
 * неверный код, превышено число попыток), но используют общий формат ответа API
 * {message, errors.code} — это часть контракта мобильного приложения, менять нельзя.
 */
abstract class InvalidOtpException extends Exception implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'code' => [$this->getMessage()],
            ],
        ], 422);
    }
}
