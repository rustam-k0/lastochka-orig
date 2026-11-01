<?php

declare(strict_types=1);

namespace App\Support\Payment;

use App\Services\IntegrationConfigService;
use Illuminate\Support\Facades\Log;

/**
 * Признак mock-режима оплаты (`YOOKASSA_MOCK_MODE`).
 *
 * Нужен там, где к магазину подключены тестовые доступы ЮKassa, но вебхук на
 * стенд настроить нельзя: подтверждения платежей ждать неоткуда. В этом режиме
 * всё, что штатно закрывает вебхук, закрывается сразу по факту запроса —
 * см. {@see \App\Services\Payment\MockPaymentGateway} и синтетические
 * подтверждения в {@see \App\Services\Payment\PaymentService}. Исключение —
 * привязка карты: до ввода карты клиентом подтверждать нечего, её закрывает
 * сверка {@see \App\Services\Payment\PaymentCardSync}.
 *
 * Предохранитель: на боевых кредах (mode=live) режим не активируется, даже если
 * флаг выставлен. Иначе забытая строка в `.env` превратила бы реальные заказы
 * в бесплатные.
 */
final class PaymentMockMode
{
    public static function enabled(): bool
    {
        if (! config('services.yookassa.mock')) {
            return false;
        }

        if (self::hasLiveCredentials()) {
            Log::warning('YooKassa mock mode is enabled with live credentials — ignored');

            return false;
        }

        return true;
    }

    private static function hasLiveCredentials(): bool
    {
        $credentials = IntegrationConfigService::getDriverCredentials('payment', 'yookassa');

        return ($credentials['mode'] ?? 'test') === 'live';
    }
}
