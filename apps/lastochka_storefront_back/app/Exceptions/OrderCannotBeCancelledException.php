<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class OrderCannotBeCancelledException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly OrderStatus $status,
    ) {
        parent::__construct(
            "Заказ нельзя отменить в статусе '{$status->label()}'"
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'status' => [$this->getMessage()],
            ],
        ], 422);
    }
}
