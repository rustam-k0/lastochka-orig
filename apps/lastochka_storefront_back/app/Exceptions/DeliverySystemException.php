<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

final class DeliverySystemException extends Exception
{
    public function __construct(string $message = 'Сервис доставки временно недоступен. Попробуйте позже.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 503);
    }
}
