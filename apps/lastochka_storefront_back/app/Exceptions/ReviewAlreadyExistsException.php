<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class ReviewAlreadyExistsException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Вы уже оставляли отзыв');
    }

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
