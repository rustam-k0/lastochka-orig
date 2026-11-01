<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class OtpRateLimitException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Превышен лимит запросов OTP. Попробуйте позже.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 429);
    }
}
