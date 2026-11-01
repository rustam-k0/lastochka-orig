<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentInfo;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Досохраняет карты клиента, подтверждённые в ЮKassa, но не дошедшие до нас
 * вебхуком (MID-314).
 *
 * Штатный путь — вебхук `payment.waiting_for_capture`: по нему сохраняется
 * карта и из привязки (холд 1₽), и из оплаты заказа новой картой. Вебхук
 * теряется, когда URL уведомлений магазина ведёт на другой стенд (тестовый
 * магазин ЮKassa общий у dev и stage) или стенд был недоступен. На стенде с
 * mock-режимом вебхука нет вовсе, а холд заказа подтверждается в момент
 * создания платежа, когда клиент ещё не ввёл карту.
 *
 * Поэтому список карт перед ответом сверяет со шлюзом платежи клиента за
 * последние дни, по которым карта ещё не сохранена:
 *  - привязка: подтверждается штатным обработчиком вебхука (карта
 *    сохраняется, холд 1₽ снимается);
 *  - оплата заказа: только сохраняется карта и привязывается к заказу.
 *    Состояние оплаты (held_amount, события в курьерку) остаётся за вебхуком
 *    и сверками заказа — {@see \App\Services\Order\DraftOrderPaymentSync},
 *    {@see PaymentService::settleAssemblyPayment()}.
 *
 * Источник правды — ЮKassa: карта берётся только из реально оплаченного
 * платежа (`paid`). Mock-режим выдаёт `pending` за `waiting_for_capture`, но
 * `paid` не трогает, поэтому до ввода карты ничего не подтвердит.
 */
final class PaymentCardSync
{
    /**
     * Сколько дней платёж остаётся кандидатом на сверку. Холд по карте ЮKassa
     * держит 7 дней, дальше платёж отменяется сам; опрашивать шлюз по давно
     * брошенным платежам при каждом открытии списка незачем.
     */
    private const LOOKBACK_DAYS = 7;

    public function __construct(private readonly PaymentService $paymentService) {}

    public function syncForUser(User $user): void
    {
        $this->syncBindings($user);
        $this->syncOrderPayments($user);
    }

    private function syncBindings(User $user): void
    {
        $bindings = PaymentTransaction::query()
            ->whereNull('order_id')
            ->where('type', PaymentTransactionType::Hold)
            ->where('status', PaymentTransactionStatus::Pending)
            ->whereNotNull('yookassa_payment_id')
            ->where('metadata->bind_user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->orderBy('id')
            ->get();

        foreach ($bindings as $binding) {
            $this->syncBinding($binding);
        }
    }

    private function syncBinding(PaymentTransaction $binding): void
    {
        $paymentId = $binding->yookassa_payment_id;

        $info = $this->paymentState($paymentId);
        if ($info === null) {
            return;
        }

        if (in_array($info->status, ['waiting_for_capture', 'succeeded'], true) && $info->paid) {
            Log::warning('Card binding confirmed without webhook', [
                'payment_id' => $paymentId,
                'user_id' => $binding->metadata['bind_user_id'] ?? null,
            ]);

            // Штатный обработчик вебхука: сохранит карту и снимет холд 1₽.
            $this->paymentService->handleHoldSucceeded($paymentId);

            return;
        }

        if ($info->status === 'canceled') {
            $this->paymentService->handleHoldCanceled($paymentId);
        }

        // pending — клиент ещё на странице ЮKassa (или бросил её): ждём.
    }

    /**
     * Заказы, оплаченные новой картой: у заказа по сохранённой карте
     * payment_card_id заполнен с момента оформления.
     */
    private function syncOrderPayments(User $user): void
    {
        $holds = PaymentTransaction::query()
            ->where('type', PaymentTransactionType::Hold)
            ->whereIn('status', [PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded])
            ->whereNotNull('yookassa_payment_id')
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->whereHas('order', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('payment_type', PaymentType::Card)
                ->whereNull('payment_card_id'))
            ->orderBy('id')
            ->get();

        foreach ($holds as $hold) {
            $this->syncOrderPayment($hold, $user);
        }
    }

    private function syncOrderPayment(PaymentTransaction $hold, User $user): void
    {
        $info = $this->paymentState($hold->yookassa_payment_id);

        if ($info === null || ! $info->paid || $info->lastFour === null) {
            return;
        }

        DB::transaction(function () use ($hold, $user, $info) {
            // Карту заказу могли уже привязать: вебхук или предыдущий платёж
            // того же заказа (повторная оплата создаёт новый холд).
            $order = Order::whereKey($hold->order_id)->lockForUpdate()->first();
            if (! $order || $order->payment_card_id !== null) {
                return;
            }

            $card = $this->paymentService->saveCardFromPayment($user, $info);
            if (! $card) {
                return;
            }

            $order->update(['payment_card_id' => $card->id]);

            Log::warning('Order payment card saved without webhook', [
                'order_id' => $order->id,
                'payment_id' => $hold->yookassa_payment_id,
                'user_id' => $user->id,
            ]);
        });
    }

    private function paymentState(string $paymentId): ?PaymentInfo
    {
        try {
            return $this->paymentService->getPaymentState($paymentId);
        } catch (\Throwable $e) {
            // Шлюз недоступен: список отдаём как есть, сверим при следующем открытии.
            Log::warning('Payment card sync: payment state request failed', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
