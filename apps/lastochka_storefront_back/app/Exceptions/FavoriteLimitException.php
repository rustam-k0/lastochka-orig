<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class FavoriteLimitException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Достигнут лимит избранного (максимум 500 товаров).');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'productId' => [$this->getMessage()],
            ],
        ], 422);
    }
}
