<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class OrderAlreadyHasReviewException extends Exception implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'review' => [$this->getMessage()],
            ],
        ], 422);
    }
}