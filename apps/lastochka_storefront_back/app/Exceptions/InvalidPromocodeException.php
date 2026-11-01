<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class InvalidPromocodeException extends Exception implements ShouldntReport
{
    public function __construct(string $message = 'Промокод недействителен.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'promocode' => [$this->getMessage()],
            ],
        ], 422);
    }
}
