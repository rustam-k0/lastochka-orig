<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class StoreInactiveException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly string $storeName,
    ) {
        parent::__construct("Магазин «{$storeName}» временно не принимает заказы");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'storeId' => [$this->getMessage()],
            ],
        ], 422);
    }
}
