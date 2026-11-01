<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

final class DeliveryZoneNotFoundException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Доставка по указанным координатам недоступна');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'zone_not_found',
            'message' => $this->getMessage(),
        ], 422);
    }
}
