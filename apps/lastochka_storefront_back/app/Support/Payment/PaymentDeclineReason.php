<?php

declare(strict_types=1);

namespace App\Support\Payment;

/**
 * Машинные коды причин отказа онлайн-оплаты (ЮKassa cancellation_details.reason).
 *
 * Клиент (мобильное приложение) идентифицирует кейс «недостаточно средств» и
 * прочие отказы по машинному коду `reason`, а НЕ по тексту сообщения — текст
 * может меняться, его сравнение хрупкое. Этот helper — единственная точка,
 * где код причины превращается в человекочитаемое сообщение (для message),
 * и используется и синхронным флоу (YooKassaService::hold), и webhook'ом
 * (PaymentService::handleHoldCanceled).
 *
 * Полный справочник кодов:
 * https://yookassa.ru/developers/payment-acceptance/after-the-payment/declined-payments
 */
final class PaymentDeclineReason
{
    /**
     * Код «недостаточно средств» — основной кейс модалки на клиенте.
     */
    public const INSUFFICIENT_FUNDS = 'insufficient_funds';

    /**
     * Код «в заказе товары 18+» — онлайн-оплата запрещена, оплата только на
     * кассе при получении. Не ЮKassa-код: отказ выносит само приложение до
     * создания платежа (PaymentService::retryPayment).
     */
    public const ADULT_ONLY_OFFLINE_PAYMENT = 'adult_only_offline_payment';

    /**
     * Человекочитаемое сообщение по коду причины отказа.
     */
    public static function message(?string $reason): string
    {
        return match (self::normalize($reason)) {
            self::ADULT_ONLY_OFFLINE_PAYMENT => 'В заказе есть товары 18+, оплата возможна только на кассе при получении',
            'insufficient_funds' => 'Недостаточно средств на карте',
            'card_expired' => 'Срок действия карты истёк',
            'invalid_card_number' => 'Неверный номер карты',
            'call_issuer', 'general_decline' => 'Платёж отклонён банком',
            'payment_method_restricted' => 'Операции по карте ограничены банком',
            'fraud_suspected' => 'Платёж отклонён: подозрение в мошенничестве',
            default => 'Платёж отклонён',
        };
    }

    /**
     * Нормализовать сырой `reason` ЮKassa: пустая строка / null → null
     * (причина не указана — клиенту нечего матчить).
     */
    public static function normalize(?string $reason): ?string
    {
        $reason = $reason !== null ? trim($reason) : null;

        return $reason === '' ? null : $reason;
    }
}
