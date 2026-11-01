<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class StoreAccessDeniedException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Магазин недоступен');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'storeId' => [$this->getMessage()],
            ],
        ], 403);
    }
}
