<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * Время слота доставки заказа истекло — дополнение недоступно (ALD-130).
 *
 * Это подстраховка на случай гонки внутри транзакции {@see \App\Actions\Order\SupplementOrderAction}:
 * основной отказ клиент получает раньше, на уровне {@see \App\Policies\OrderPolicy::supplement()} (403).
 */
final class OrderSupplementSlotExpiredException extends Exception implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Время слота доставки истекло, дополнение заказа недоступно');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'deliverySlot' => [$this->getMessage()],
            ],
        ], 409);
    }
}
