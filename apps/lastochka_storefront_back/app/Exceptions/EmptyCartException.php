<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class EmptyCartException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Корзина пуста');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'cart' => [$this->getMessage()],
            ],
        ], 422);
    }
}
