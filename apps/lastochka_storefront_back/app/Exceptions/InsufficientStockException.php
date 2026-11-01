<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class InsufficientStockException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly string $productTitle,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Товар '{$productTitle}' недоступен в нужном количестве. Доступно: {$available}, запрошено: {$requested}"
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'quantity' => [$this->getMessage()],
            ],
        ], 422);
    }
}
