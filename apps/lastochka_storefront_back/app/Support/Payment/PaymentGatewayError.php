<?php

declare(strict_types=1);

namespace App\Support\Payment;

/**
 * Перевод технических ошибок платёжного шлюза (поле `description` ответа
 * ЮKassa) в человекочитаемый текст.
 *
 * Сообщение об отказе доходит до людей без изменений: сборщик видит его
 * в модалке «Увы! Платёж не прошёл» (delivery_back подставляет `message`
 * из ответа storefront), покупатель — в уведомлении об отказе оплаты.
 * ЮKassa отвечает по-английски, поэтому сборщик в магазине читал
 * «The payment amount is smaller than the minimum payment limit» рядом
 * с советом пополнить карту, хотя средств хватало (прод-инцидент
 * 19.09.2026, заказ 336). Английский текст наружу не выходит: известные
 * ошибки переводим, остальные заменяем родовым русским fallback'ом
 * (сырой `description` остаётся в логах YooKassaService).
 *
 * @see PaymentDeclineReason — отказы по машинному коду cancellation_details.reason
 */
final class PaymentGatewayError
{
    /**
     * Подстрока ответа шлюза → человекочитаемое сообщение.
     * Сопоставление нечувствительно к регистру, порядок значения не имеет.
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        'smaller than the minimum payment limit' => 'Сумма меньше минимального платежа, который принимает банк',
        'greater than the maximum payment limit' => 'Сумма больше максимального платежа, который принимает банк',
        'not enough money' => 'Недостаточно средств на карте',
        'insufficient funds' => 'Недостаточно средств на карте',
        'payment method is not available' => 'Способ оплаты недоступен',
        'idempotence key duplicated' => 'Платёж уже обрабатывается, повторите позже',
        'invalid credentials' => 'Ошибка доступа к платёжному сервису',
    ];

    /**
     * @param  string|null  $description  Сырое `description` из ответа шлюза
     * @param  string  $fallback  Родовое сообщение для неизвестных ошибок
     */
    public static function message(?string $description, string $fallback): string
    {
        $description = $description !== null ? trim($description) : '';

        if ($description === '') {
            return $fallback;
        }

        $haystack = mb_strtolower($description);

        foreach (self::MESSAGES as $needle => $message) {
            if (str_contains($haystack, $needle)) {
                return $message;
            }
        }

        return $fallback;
    }
}
