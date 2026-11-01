<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class MinOrderAmountException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly int $minAmount,
        public readonly float $currentAmount,
    ) {
        parent::__construct(
            "Минимальная сумма заказа: {$minAmount} руб. Текущая сумма: {$currentAmount} руб."
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'subtotal' => [$this->getMessage()],
            ],
        ], 422);
    }
}
