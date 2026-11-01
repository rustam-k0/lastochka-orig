<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class YandexDeliveryException extends \RuntimeException
{
    /**
     * Рендер для API: ошибка интеграции с Яндекс-доставкой не должна выглядеть
     * как 500 со стектрейсом — клиенту отдаём понятное сообщение (ALADDIN-11465).
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'yandex_delivery_unavailable',
            'message' => 'Доставка Яндексом сейчас недоступна. Попробуйте позже или выберите другой способ доставки.',
        ], 422);
    }
}
