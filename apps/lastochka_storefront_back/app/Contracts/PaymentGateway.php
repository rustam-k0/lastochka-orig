<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Payment\PaymentInfo;
use App\DTOs\Payment\PaymentResult;
use App\DTOs\Payment\ReceiptData;
use App\Models\Order;

interface PaymentGateway
{
    /**
     * Создать холд (двухстадийная оплата).
     * save_payment_method: true всегда.
     *
     * @param  ReceiptData|null  $receipt  Фискальный чек (54-ФЗ). Обязателен,
     *                                     если к магазину подключена онлайн-касса.
     * @param  string|null  $idempotenceKey  Явный ключ идемпотентности (для pre-auth ДО
     *                                       создания заказа, когда order->id ещё нет).
     * @param  string|null  $description  Явное описание платежа (для ЛК ЮKassa).
     * @param  array<string, mixed>|null  $metadata  Явная metadata платежа.
     * @param  string|null  $returnUrl  Адрес возврата клиента после подтверждения
     *                                  платежа. Проверяется по белому списку
     *                                  ({@see \App\Support\Payment\ReturnUrl});
     *                                  null — дефолт из конфига. Применяется
     *                                  только к redirect-флоу (новая карта).
     */
    public function hold(
        Order $order,
        ?string $paymentMethodId = null,
        ?ReceiptData $receipt = null,
        ?string $idempotenceKey = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $returnUrl = null,
    ): PaymentResult;

    /**
     * Подтвердить списание (полная или частичная сумма).
     */
    public function capture(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult;

    /**
     * Отменить холд (YooKassa POST /payments/{id}/cancel).
     */
    public function cancel(string $yookassaPaymentId): PaymentResult;

    /**
     * Возврат (полный или частичный).
     */
    public function refund(string $yookassaPaymentId, float $amount, ?ReceiptData $receipt = null): PaymentResult;

    /**
     * Одностадийный платёж (capture: true) через сохранённую карту.
     * Для досписания разницы.
     */
    public function charge(Order $order, float $amount, string $paymentMethodId, ?ReceiptData $receipt = null): PaymentResult;

    /**
     * Получить информацию о платеже.
     */
    public function getPaymentInfo(string $yookassaPaymentId): PaymentInfo;

    /**
     * Получить чеки (54-ФЗ) по платежу или возврату.
     * Передаётся ровно один из аргументов. Возвращает список «сырых»
     * объектов чека ЮKassa (как массивы). Пустой массив — чеков нет или ошибка.
     *
     * @return list<array<string, mixed>>
     */
    public function getReceipts(?string $paymentId = null, ?string $refundId = null): array;

    /**
     * Независимый чек (54-ФЗ) без движения денег (POST /receipts).
     * Используется для наличной оплаты: платёж проходит мимо ЮKassa,
     * но онлайн-касса всё равно должна зарегистрировать чек.
     *
     * @param  array<string, mixed>  $payload  Тело запроса (type, send, customer, settlements, items).
     * @return array<string, mixed> «Сырой» ответ ЮKassa (id, status, ...).
     */
    public function createIndependentReceipt(array $payload): array;
}
