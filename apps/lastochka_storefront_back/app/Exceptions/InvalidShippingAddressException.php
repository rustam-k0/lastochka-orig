<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class InvalidShippingAddressException extends Exception implements ShouldntReport
{
    public function __construct(string $message = 'Некорректный адрес для выбранного метода доставки')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'addressId' => [$this->getMessage()],
            ],
        ], 422);
    }
}
