<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class DeliverySlotUnavailableException extends Exception implements ShouldntReport
{
    public function __construct() {
        parent::__construct("Слот доставки больше недоступен");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'deliverySlotId' => [$this->getMessage()],
            ],
        ], 422);
    }
}
