<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * Оформление без слота (модуль checkout_without_slots): магазин по своему
 * графику сейчас не принимает заказы.
 */
final class StoreClosedException extends Exception implements ShouldntReport
{
    public function __construct(
        public readonly string $storeName,
    ) {
        parent::__construct("Магазин «{$storeName}» сейчас не принимает заказы");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'store_closed',
            'message' => $this->getMessage(),
            'errors' => [
                'storeId' => [$this->getMessage()],
            ],
        ], 422);
    }
}
