<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\ReceiptData;
use App\DTOs\Payment\ReceiptItemData;
use App\Models\Order;
use App\Models\User;
use App\Services\IntegrationConfigService;

/**
 * Сборка фискальных чеков (54-ФЗ) для запросов в YooKassa.
 *
 * Главный инвариант: сумма позиций чека ДОЛЖНА совпадать с суммой платежа
 * до копейки, иначе YooKassa отклоняет запрос. В заказе на сумму платежа
 * влияют скидка по промокоду и списанные бонусы (total = subtotal − discount
 * − bonus_spent + delivery_cost), поэтому позиции по полным ценам не сходятся
 * с total. Решение — пропорционально распределить целевую сумму платежа по
 * позициям (товары + доставка), а остаток округления добрать на последнюю
 * позицию.
 *
 * Каждая позиция формируется с quantity = 1 и amount = итоговая стоимость
 * позиции. Это исключает зависимость от единицы измерения (штуки/граммы/кг,
 * где quantity в order_items хранится в граммах) и от per-unit округлений.
 */
final class ReceiptBuilder
{
    /**
     * Чек для холда/списания по одному заказу.
     *
     * @param  float|null  $targetAmount  Сумма платежа; по умолчанию order->total.
     *                                    Передаётся явно для частичного capture.
     */
    public function forOrder(Order $order, ?float $targetAmount = null): ?ReceiptData
    {
        if (! $this->sendReceiptEnabled()) {
            return null;
        }

        $order->loadMissing(['activeItems', 'user']);

        $target = $targetAmount ?? (float) $order->total;

        $items = $this->distribute($this->orderLines($order), $target);

        return new ReceiptData(
            items: $items,
            customerPhone: $this->normalizePhone($order->user?->phone),
            customerEmail: $order->user?->email,
            taxSystemCode: $this->taxSystemCode(),
        );
    }

    /**
     * Чек для консолидированного холда/списания marketplace client-order'а.
     *
     * @param  array<int, Order>  $orders
     */
    public function forClientOrder(array $orders, float $totalAmount): ?ReceiptData
    {
        if (! $this->sendReceiptEnabled()) {
            return null;
        }

        $lines = [];
        $user = null;

        foreach ($orders as $order) {
            $order->loadMissing(['activeItems', 'user']);
            $user ??= $order->user;
            $lines = array_merge($lines, $this->orderLines($order));
        }

        $items = $this->distribute($lines, $totalAmount);

        return new ReceiptData(
            items: $items,
            customerPhone: $this->normalizePhone($user?->phone),
            customerEmail: $user?->email,
            taxSystemCode: $this->taxSystemCode(),
        );
    }

    /**
     * Чек для привязки карты (холд 1₽, который затем отменяется).
     */
    public function forBind(User $user, float $amount = 1.0): ?ReceiptData
    {
        if (! $this->sendReceiptEnabled()) {
            return null;
        }

        return new ReceiptData(
            items: [new ReceiptItemData(
                description: 'Привязка банковской карты',
                amount: round($amount, 2),
                vatCode: $this->vatCode(),
                quantity: 1.0,
                paymentSubject: 'service',
                paymentMode: 'full_payment',
            )],
            customerPhone: $this->normalizePhone($user->phone),
            customerEmail: $user->email,
            taxSystemCode: $this->taxSystemCode(),
        );
    }

    /**
     * Чек для пред-авторизации (холд по сохранённой карте ДО создания заказа).
     *
     * Заказа ещё нет, детальные позиции неизвестны → одна обобщённая позиция
     * на сумму холда. ЮKassa сверяет лишь сумму чека с суммой платежа, а
     * детальный фискальный чек формируется на capture через forOrder().
     */
    public function forPreauth(User $user, float $amount): ?ReceiptData
    {
        if (! $this->sendReceiptEnabled()) {
            return null;
        }

        return new ReceiptData(
            items: [new ReceiptItemData(
                description: 'Оплата заказа',
                amount: round($amount, 2),
                vatCode: $this->vatCode(),
                quantity: 1.0,
                paymentSubject: $this->paymentSubject(),
                paymentMode: $this->paymentMode(),
            )],
            customerPhone: $this->normalizePhone($user->phone),
            customerEmail: $user->email,
            taxSystemCode: $this->taxSystemCode(),
        );
    }

    /**
     * Чек для досписания разницы по заказу (одностадийный платёж).
     */
    public function forCharge(Order $order, float $amount): ?ReceiptData
    {
        if (! $this->sendReceiptEnabled()) {
            return null;
        }

        $order->loadMissing('user');

        return new ReceiptData(
            items: [new ReceiptItemData(
                description: "Доплата по заказу #{$order->id}",
                amount: round($amount, 2),
                vatCode: $this->vatCode(),
                quantity: 1.0,
                paymentSubject: $this->paymentSubject(),
                paymentMode: 'full_payment',
            )],
            customerPhone: $this->normalizePhone($order->user?->phone),
            customerEmail: $order->user?->email,
            taxSystemCode: $this->taxSystemCode(),
        );
    }

    /**
     * Чек возврата по одному заказу: те же позиции, распределённые на сумму возврата.
     */
    public function forRefund(Order $order, float $amount): ?ReceiptData
    {
        return $this->forOrder($order, $amount);
    }

    /**
     * Чек возврата по консолидированному client-order'у.
     *
     * @param  array<int, Order>  $orders
     */
    public function forClientOrderRefund(array $orders, float $amount): ?ReceiptData
    {
        return $this->forClientOrder($orders, $amount);
    }

    /**
     * Базовые строки заказа: товары (по фактической стоимости позиции) + доставка.
     *
     * Берём activeItems: отменённые и заменённые при сборке позиции не входят в
     * `orders.total`, поэтому их присутствие в чеке не только добавляет не
     * отданные клиенту товары, но и занижает цены остальных позиций —
     * distribute() размазывает разницу пропорционально по всем строкам.
     *
     * @return list<array{description: string, base: float, subject: string}>
     */
    private function orderLines(Order $order): array
    {
        $lines = [];

        foreach ($order->activeItems as $item) {
            $lines[] = [
                'description' => $item->title !== null && $item->title !== '' ? $item->title : 'Товар',
                'base' => (float) $item->total,
                'subject' => $this->paymentSubject(),
                'vat_code' => $item->vat_rate !== null ? (int) $item->vat_rate : null,
            ];
        }

        if ((float) $order->delivery_cost > 0) {
            $lines[] = [
                'description' => 'Доставка',
                'base' => (float) $order->delivery_cost,
                'subject' => 'service',
                'vat_code' => null,
            ];
        }

        return $lines;
    }

    /**
     * Распределить сумму платежа $target по строкам пропорционально их base.
     * Остаток округления добирается на последнюю позицию, поэтому сумма
     * получившихся позиций строго равна $target.
     *
     * @param  list<array{description: string, base: float, subject: string, vat_code?: int|null}>  $lines
     * @return list<ReceiptItemData>
     */
    private function distribute(array $lines, float $target): array
    {
        $target = round($target, 2);
        $base = array_sum(array_map(static fn (array $l): float => $l['base'], $lines));

        // Нет позиций или нулевая база — одна обобщённая позиция на весь платёж.
        if ($lines === [] || $base <= 0.0) {
            return [$this->makeItem('Оплата заказа', max($target, 0.0), $this->paymentSubject(), null)];
        }

        $amounts = [];
        foreach ($lines as $i => $line) {
            $amounts[$i] = round($target * $line['base'] / $base, 2);
        }

        // Доводка: разницу target − Σamounts вешаем на последнюю строку.
        $lastIdx = array_key_last($amounts);
        $amounts[$lastIdx] = round($amounts[$lastIdx] + ($target - array_sum($amounts)), 2);

        $items = [];
        foreach ($lines as $i => $line) {
            if ($amounts[$i] <= 0.0) {
                // Позиция схлопнулась в ноль после распределения (крупные скидки/бонусы);
                // YooKassa не принимает нулевые позиции — пропускаем.
                continue;
            }
            $items[] = $this->makeItem($line['description'], $amounts[$i], $line['subject'], $line['vat_code'] ?? null);
        }

        if ($items === []) {
            return [$this->makeItem('Оплата заказа', $target, $this->paymentSubject(), null)];
        }

        // Если из-за отброшенных нулевых позиций сумма разошлась с target — добрать на последнюю.
        $sum = array_reduce($items, static fn (float $c, ReceiptItemData $it): float => $c + $it->amount, 0.0);
        $delta = round($target - $sum, 2);
        if (abs($delta) >= 0.01) {
            $last = $items[count($items) - 1];
            $items[count($items) - 1] = new ReceiptItemData(
                description: $last->description,
                amount: round($last->amount + $delta, 2),
                vatCode: $last->vatCode,
                quantity: 1.0,
                paymentSubject: $last->paymentSubject,
                paymentMode: $last->paymentMode,
            );
        }

        return $items;
    }

    private function makeItem(string $description, float $amount, string $subject, ?int $vatCode = null): ReceiptItemData
    {
        return new ReceiptItemData(
            description: $description !== '' ? $description : 'Товар',
            amount: round($amount, 2),
            vatCode: $vatCode ?? $this->vatCode(),
            quantity: 1.0,
            paymentSubject: $subject,
            paymentMode: $this->paymentMode(),
        );
    }

    /**
     * Телефон в формате YooKassa (ITU-T E.164 без «+»), например 79991234567.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (mb_strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.mb_substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Включена ли отправка фискального чека. Дефолт true — иначе на проде с
     * подключённой онлайн-кассой YooKassa отклонит платёж (422).
     */
    private function sendReceiptEnabled(): bool
    {
        // Если фискализирует АТОЛ — ЮKassa чек НЕ шлём (иначе двойной чек).
        if (IntegrationConfigService::getDriver('fiscal') === 'atol') {
            return false;
        }

        $value = $this->credentials()['send_receipt'] ?? config('services.yookassa.send_receipt', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function vatCode(): int
    {
        $value = $this->credentials()['vat_code'] ?? null;
        if ($value === null || $value === '') {
            return (int) config('services.yookassa.vat_code', 4);
        }

        return (int) $value;
    }

    private function taxSystemCode(): ?int
    {
        $value = $this->credentials()['tax_system_code'] ?? config('services.yookassa.tax_system_code');
        // 'none' — sentinel «Не указывать» из настроек; '' — несохранённое значение.
        if ($value === null || $value === '' || $value === 'none') {
            return null;
        }

        return (int) $value;
    }

    private function paymentSubject(): string
    {
        return (string) ($this->credentials()['payment_subject'] ?? config('services.yookassa.payment_subject', 'commodity'));
    }

    private function paymentMode(): string
    {
        return (string) ($this->credentials()['payment_mode'] ?? config('services.yookassa.payment_mode', 'full_prepayment'));
    }

    /**
     * Фискальные настройки из credentials интеграции payment (если заданы через админку),
     * с приоритетом над config. Возвращает поддиктив yookassa или корень credentials.
     *
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        $credentials = IntegrationConfigService::getCredentials('payment');

        return $credentials['yookassa'] ?? $credentials;
    }
}
