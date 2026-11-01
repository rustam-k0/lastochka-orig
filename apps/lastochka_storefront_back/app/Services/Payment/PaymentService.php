<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use App\DTOs\Payment\PaymentInfo;
use App\DTOs\Payment\PaymentResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Jobs\FiscalizeRefundJob;
use App\Jobs\FiscalizeSaleJob;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\RabbitMQ\Debug\LoggingEventPublisher;
use App\Services\IntegrationConfigService;
use App\Support\Payment\PaymentDeclineReason;
use App\Support\Payment\PaymentMockMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PaymentService
{
    /**
     * Минимальная сумма платежа, которую принимает платёжный шлюз.
     *
     * ЮKassa отклоняет любой платёж меньше 1 ₽ ошибкой «The payment amount is
     * smaller than the minimum payment limit», поэтому копеечный недобор
     * (пересчёт весовых позиций по фактическому весу после сборки) нельзя
     * дособрать отдельным платежом — его прощают в пользу покупателя.
     */
    public const MIN_GATEWAY_CHARGE = 1.0;

    public function __construct(
        private PaymentGateway $gateway,
        private ReceiptBuilder $receiptBuilder,
        private ReceiptSyncService $receiptSync,
    ) {}

    /**
     * Создать холд на сумму заказа.
     *
     * @param  string|null  $returnUrl  Адрес возврата клиента после оплаты
     *                                  (redirect-флоу новой карты); null — дефолт из конфига.
     */
    public function createHold(Order $order, ?string $paymentMethodId = null, ?string $returnUrl = null): PaymentResult
    {
        $result = $this->gateway->hold(
            $order,
            $paymentMethodId,
            $this->receiptBuilder->forOrder($order),
            returnUrl: $returnUrl,
        );

        PaymentTransaction::create([
            'order_id' => $order->id,
            'type' => PaymentTransactionType::Hold,
            'status' => $result->success ? PaymentTransactionStatus::Pending : PaymentTransactionStatus::Canceled,
            'amount' => (float) $order->total,
            'yookassa_payment_id' => $result->paymentId,
            'payment_method_id' => $result->paymentMethodId ?? $paymentMethodId,
            'error_message' => $result->errorMessage,
            'decline_reason' => $result->declineReason,
        ]);

        $this->mockConfirmHold($result);

        return $result;
    }

    /**
     * Пред-авторизация по сохранённой карте ДО создания заказа.
     *
     * Синхронный холд: при недостатке средств / отказе банка ЮKassa отвечает
     * HTTP 200 со status='canceled' → gateway->hold вернёт success=false, и
     * вызывающий код (CreateOrderAction) НЕ создаёт заказ — он «не ложится в базу».
     *
     * Заказа ещё нет, поэтому PaymentTransaction здесь НЕ пишем — её создаёт
     * attachPreauthorizedHold() уже после фиксации заказа в БД.
     */
    public function preauthorizeSavedCard(
        User $user,
        float $amount,
        string $paymentMethodId,
        ?string $idempotencyKey = null,
    ): PaymentResult {
        $amount = round($amount, 2);

        // Временный (несохранённый) Order — контейнер суммы холда. Описание,
        // metadata и idempotence-key передаём явно: заказа в БД ещё нет, и
        // строковый id не годится (Order кастит id к int).
        $tmpOrder = new Order;
        $tmpOrder->total = $amount;
        $tmpOrder->setRelation('user', $user);

        $idempotenceKey = 'preauth-'.($idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : Str::uuid()->toString());

        $result = $this->gateway->hold(
            order: $tmpOrder,
            paymentMethodId: $paymentMethodId,
            receipt: $this->receiptBuilder->forPreauth($user, $amount),
            idempotenceKey: $idempotenceKey,
            description: 'Оплата заказа',
            metadata: ['preauth' => true],
        );

        if (! $result->success) {
            Log::info('Pre-authorization declined', [
                'user_id' => $user->id,
                'amount' => $amount,
                'status' => $result->status,
                'error' => $result->errorMessage,
            ]);
        }

        return $result;
    }

    /**
     * Привязать успешный pre-auth холд к уже созданному заказу.
     *
     * Заменяет createHold() на пути сохранённой карты: платёж в ЮKassa уже
     * создан в preauthorizeSavedCard(), здесь только фиксируем транзакцию.
     * Если холд синхронно подтверждён (waiting_for_capture) — сразу проставляем
     * held_amount, не дожидаясь webhook'а (он может прийти раньше записи транзакции).
     */
    public function attachPreauthorizedHold(Order $order, PaymentResult $preauth, float $amount): void
    {
        $amount = round($amount, 2);

        DB::transaction(function () use ($order, $preauth, $amount) {
            $this->lockOrder($order);

            $held = $preauth->status === 'waiting_for_capture';

            PaymentTransaction::create([
                'order_id' => $order->id,
                'type' => PaymentTransactionType::Hold,
                'status' => $held ? PaymentTransactionStatus::Succeeded : PaymentTransactionStatus::Pending,
                'amount' => $amount,
                'yookassa_payment_id' => $preauth->paymentId,
                'payment_method_id' => $preauth->paymentMethodId,
            ]);

            if ($held) {
                $order->update(['held_amount' => $amount]);
                // MID-285: pre-auth подтверждён синхронно — момент холда известен
                // прямо здесь. Заказ ещё не опубликован в курьерку (CreateOrderAction
                // шлёт order.created позже), метка уедет вместе с ним.
                $this->markPaymentHeld($order);
            }
        });
    }

    /**
     * Отменить холд напрямую по payment_id (компенсация, когда транзакция
     * создания заказа откатилась и записи PaymentTransaction в БД ещё нет).
     */
    public function cancelHoldByPaymentId(string $yookassaPaymentId): PaymentResult
    {
        return $this->gateway->cancel($yookassaPaymentId);
    }

    /**
     * Подтвердить списание (capture).
     */
    public function capturePayment(Order $order, float $amount): PaymentResult
    {
        $holdTransaction = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->latest()
            ->first();

        if (! $holdTransaction || ! $holdTransaction->yookassa_payment_id) {
            Log::warning('capturePayment: no active hold', ['order_id' => $order->id]);

            return new PaymentResult(success: false, errorMessage: 'Нет активного холда');
        }

        $result = $this->gateway->capture(
            $holdTransaction->yookassa_payment_id,
            $amount,
            $this->receiptBuilder->forOrder($order, $amount),
        );

        return DB::transaction(function () use ($order, $amount, $result, $holdTransaction) {
            // X-lock на заказ ДО вставки capture: вебхук payment.succeeded приходит
            // через ~200 мс после capture и делает тот же insert → update. Без
            // блокировки оба держат S-lock от FK и ждут X-lock → deadlock (#837)
            // либо дубль capture-строки по одному payment_id. См. lockOrder().
            $this->lockOrder($order);

            // Вебхук payment.succeeded по этому же платежу мог успеть раньше: ЮKassa
            // шлёт его сразу после capture, а транзакцию мы открываем только получив
            // ответ шлюза. Тогда вебхук уже записал capture, перевёл заказ в
            // оплаченные, опубликовал order_paid и поставил чек — повтор давал дубль
            // capture-строки, второй order_paid и второй FiscalizeSaleJob (прод-заказ
            // 450, GlitchTip 847; так было в 95 из 155 карточных заказов).
            if ($result->success && $this->hasSucceededCapture($order, $holdTransaction->yookassa_payment_id)) {
                $order->update([
                    'captured_amount' => $this->recalcCapturedAmount($order),
                    'is_paid' => true,
                ]);

                return $result;
            }

            PaymentTransaction::create([
                'order_id' => $order->id,
                'type' => PaymentTransactionType::Capture,
                'status' => $result->success ? PaymentTransactionStatus::Succeeded : PaymentTransactionStatus::Canceled,
                'amount' => $amount,
                'yookassa_payment_id' => $holdTransaction->yookassa_payment_id,
                'error_message' => $result->errorMessage,
            ]);

            if ($result->success) {
                $order->update([
                    'captured_amount' => $this->recalcCapturedAmount($order),
                    'is_paid' => true,
                ]);

                $this->publishOrderPaid($order);
                $this->dispatchAtolSaleIfNeeded($order);
            }

            return $result;
        });
    }

    /**
     * Возврат (полный или частичный).
     */
    public function refundPayment(Order $order, float $amount): PaymentResult
    {
        $alreadyRefunded = (float) ($order->refunded_amount ?? 0);
        $maxRefundable = (float) ($order->captured_amount ?? 0) - $alreadyRefunded;

        if ($amount > $maxRefundable) {
            return new PaymentResult(
                success: false,
                errorMessage: "Максимальная сумма возврата: {$maxRefundable}",
            );
        }

        // Собираем succeeded capture-транзакции с уникальными payment_id
        // и считаем сколько уже возвращено по каждому
        $captures = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->orderBy('id')
            ->get();

        if ($captures->isEmpty()) {
            return new PaymentResult(success: false, errorMessage: 'Нет успешного списания для возврата');
        }

        $refunds = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Refund)
            ->whereIn('status', [PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded])
            ->get();

        // Реально захваченная сумма по каждому payment_id. Дубли capture одного
        // платежа (гонка capturePayment + webhook payment.succeeded по одному PID)
        // имеют одинаковую сумму и означают ОДИН захват на стороне YooKassa —
        // берём максимум по группе, а не сумму: иначе доступная к возврату сумма
        // раздувается и весь возврат уходит на один платёж, не доходя до досписания.
        $remainByPayment = [];
        foreach ($captures->groupBy('yookassa_payment_id') as $pid => $group) {
            $remainByPayment[$pid] = (float) $group->max('amount');
        }
        foreach ($refunds as $ref) {
            $pid = $ref->yookassa_payment_id;
            if (isset($remainByPayment[$pid])) {
                $remainByPayment[$pid] -= (float) $ref->amount;
            }
        }

        // Распределяем возврат по платежам
        $remaining = $amount;
        $lastResult = null;

        foreach ($remainByPayment as $paymentId => $available) {
            if ($remaining <= 0 || $available <= 0) {
                continue;
            }

            $refundAmount = min($remaining, $available);
            $result = $this->gateway->refund(
                $paymentId,
                $refundAmount,
                $this->receiptBuilder->forRefund($order, $refundAmount),
            );

            DB::transaction(function () use ($order, $refundAmount, $result, $paymentId) {
                PaymentTransaction::create([
                    'order_id' => $order->id,
                    'type' => PaymentTransactionType::Refund,
                    'status' => $result->success ? PaymentTransactionStatus::Pending : PaymentTransactionStatus::Canceled,
                    'amount' => $refundAmount,
                    'yookassa_payment_id' => $paymentId,
                    'yookassa_refund_id' => $result->refundId,
                    'error_message' => $result->errorMessage,
                ]);
            });

            if (! $result->success) {
                return $result;
            }

            $this->mockConfirmRefund($result, (string) $paymentId, $refundAmount);

            $remaining -= $refundAmount;
            $lastResult = $result;
        }

        return $lastResult ?? new PaymentResult(success: false, errorMessage: 'Нет доступных платежей для возврата');
    }

    /**
     * Досписание — автоплатёж на разницу через сохранённую карту.
     * Используется когда фактическая сумма сборки больше холда.
     */
    public function chargeExtra(Order $order, float $amount): PaymentResult
    {
        $card = $order->paymentCard;

        if (! $card) {
            return new PaymentResult(success: false, errorMessage: 'Нет сохранённой карты для досписания');
        }

        $result = $this->gateway->charge(
            $order,
            $amount,
            $card->payment_method_id,
            $this->receiptBuilder->forCharge($order, $amount),
        );

        return DB::transaction(function () use ($order, $amount, $result) {
            $this->lockOrder($order);

            PaymentTransaction::create([
                'order_id' => $order->id,
                'type' => PaymentTransactionType::Capture,
                'status' => $result->success ? PaymentTransactionStatus::Succeeded : PaymentTransactionStatus::Canceled,
                'amount' => $amount,
                'yookassa_payment_id' => $result->paymentId,
                'metadata' => ['extra_charge' => true],
                'error_message' => $result->errorMessage,
            ]);

            if ($result->success) {
                $order->update([
                    'captured_amount' => $this->recalcCapturedAmount($order),
                ]);
            }

            return $result;
        });
    }

    /**
     * Синхронное списание итоговой стоимости при завершении сборки
     * (модуль collector_payment, кнопка сборщика «Списать оплату»).
     *
     * Порядок согласован с ПМ (2026-07-23): СНАЧАЛА доплата разницы
     * (единственная операция, которая может упасть по недостатку средств),
     * ПОТОМ capture холда (необратимый, но гарантированный — деньги
     * зарезервированы банком). Провал доплаты оставляет заказ полностью
     * не списанным: холд цел, повторные вызовы безопасны, отмена = снятие
     * холда без возвратов.
     *
     * Обрыв связи с ЮKassa (ConnectionException) пробрасывается вызывающему:
     * повтор безопасен на любом шаге (ключи идемпотентности у доплаты и
     * capture детерминированы), endpoint отвечает 503 «повторите». До проброса
     * ставим страховочный дедлайн, как при отказе capture: если сборщик так и
     * не повторит, автоотмена снимет холд и вернёт доплату.
     *
     * @throws ConnectionException
     */
    public function settleAssemblyPayment(Order $order, float $grandTotal): PaymentResult
    {
        try {
            return $this->settleAssemblyPaymentOnce($order, $grandTotal);
        } catch (ConnectionException $e) {
            Log::warning('settleAssemblyPayment: payment gateway unreachable', [
                'order_id' => $order->id,
                'grand_total' => $grandTotal,
                'error' => $e->getMessage(),
            ]);

            $this->markCaptureRetryPending($order->fresh());

            throw $e;
        }
    }

    private function settleAssemblyPaymentOnce(Order $order, float $grandTotal): PaymentResult
    {
        if ($order->payment_type !== PaymentType::Card) {
            return new PaymentResult(success: true);
        }

        $captured = $this->recalcCapturedAmount($order);

        // Идемпотентность: уже списано полностью (повторный вызов сборщика или
        // обрыв связи ПОСЛЕ полного успеха — delivery ретраит, отвечаем успехом)
        if ($captured >= $grandTotal - 0.01) {
            $this->clearSurcharge($order);

            return new PaymentResult(success: true);
        }

        // Холд закрыт capture'ом, а остаток меньше минимального платежа шлюза:
        // расчёт окончен, копейки прощены (см. MIN_GATEWAY_CHARGE). Повторный
        // вызов не должен уходить в шлюз — второй capture по закрытому холду
        // ЮKassa отклонит, и сборщик снова упрётся в ошибку на экране.
        if ($captured > 0.01 && $captured >= $grandTotal - self::MIN_GATEWAY_CHARGE) {
            $this->clearSurcharge($order);

            return new PaymentResult(success: true);
        }

        $held = (float) ($order->held_amount ?? 0);

        // held_amount проставляется только при ПОДТВЕРЖДЁННОМ холде (синхронный
        // ответ waiting_for_capture или webhook payment.waiting_for_capture).
        // Потерянный webhook оставляет поле пустым при живом холде у банка —
        // без сверки мы бы списали всю сумму вторым платежом, а холд продолжил
        // морозить деньги покупателя до истечения (двойная заморозка).
        if ($held <= 0.01) {
            $held = $this->reconcileUnconfirmedHold($order);
        }

        // Retry-safe: уже собранные доплаты (succeeded, metadata.extra_charge)
        // вычитаем — обрыв между доплатой и capture не приводит к повторной доплате
        $extraCharged = $this->extraChargedAmount($order);
        $extraNeeded = max(0.0, round($grandTotal - $held, 2));
        $extraToCharge = round($extraNeeded - $extraCharged, 2);

        // Недобор меньше минимального платежа шлюза (прод-инцидент 19.09.2026:
        // холд 2657.60, итог сборки 2657.69 — весовая позиция пересчиталась по
        // фактическому весу). Доплату на такую сумму ЮKassa не проводит, и без
        // этого порога сборщик получал отказ на каждое «Завершить», а заказ
        // стоял в магазине. Копейки прощаем и закрываем расчёт capture'ом холда.
        // Холда нет — прощать нечего: сумма уходит в шлюз целиком.
        if ($held > 0.01 && $extraToCharge > 0.01 && $extraToCharge < self::MIN_GATEWAY_CHARGE) {
            Log::info('Sub-minimum surcharge forgiven', [
                'order_id' => $order->id,
                'grand_total' => $grandTotal,
                'held_amount' => $held,
                'shortfall' => $extraToCharge,
            ]);

            $extraToCharge = 0.0;
        }

        if ($extraToCharge > 0.01) {
            $chargeResult = $this->chargeExtra($order, $extraToCharge);

            if (! $chargeResult->success) {
                $this->markAwaitingSurcharge($order, $extraToCharge);

                return $chargeResult;
            }

            // Доплата прошла — заказ больше НЕ «ожидает доплату». Чистим стейл
            // surcharge_amount от предыдущей неудачной попытки СРАЗУ: если следом
            // capture холда упадёт, markCaptureRetryPending поставит свежий дедлайн
            // (корректный переход awaiting_surcharge → retry_pending). Без этого
            // endpoint увидел бы залипший surcharge_amount и отдал 402 «доплатите»
            // вместо 503 retry, хотя доплата уже списана.
            $this->clearSurcharge($order->fresh());

            $extraCharged = round($extraCharged + $extraToCharge, 2);
        }

        if ($held <= 0.01) {
            // Холда не было — вся сумма собрана доплатой. Локально помечаем
            // оплату: chargeExtra (в отличие от capturePayment) is_paid не ставит,
            // иначе storefront разошёлся бы с платформой (по событию djami ставит
            // paid=true, а локально осталось бы false).
            $fresh = $order->fresh();
            $fresh->update(['is_paid' => true]);
            $this->clearSurcharge($fresh);
            $this->publishOrderPaid($fresh);
            // Единственный путь расчёта, не проходящий через capturePayment:
            // без явного диспатча продажа осталась бы без фискального чека.
            $this->dispatchAtolSaleIfNeeded($fresh);

            return new PaymentResult(success: true);
        }

        $captureAmount = round(min($held, $grandTotal - $extraCharged), 2);
        $captureResult = $this->capturePayment($order->fresh(), $captureAmount);

        if (! $captureResult->success) {
            // «Доплата списалась, capture холда не прошёл» (сеть/сбой ЮKassa).
            // Это НЕ «ожидает доплату»: surcharge_amount не ставим, пуш клиенту
            // не шлём. Ставим только страховочный дедлайн — если ретраи сборщика
            // так и не пройдут, автоотмена вернёт доплату и снимет холд.
            $this->markCaptureRetryPending($order->fresh());

            return $captureResult;
        }

        $this->clearSurcharge($order->fresh());

        return $captureResult;
    }

    /**
     * Сверить неподтверждённый холд с фактическим состоянием платежа в ЮKassa.
     *
     * Вызывается, когда `held_amount` пуст, но hold-транзакция существует
     * в статусе pending. Если платёж реально в `waiting_for_capture` — холд
     * жив (webhook потерялся или ещё в пути): достраиваем локальное состояние
     * и возвращаем сумму холда, чтобы списание пошло capture'ом. Если платёж
     * в любом другом статусе (pending / canceled) — холда нет, возвращаем 0
     * и сумма собирается доплатой, как прежде.
     *
     * @return float Сумма живого холда либо 0.0
     */
    private function reconcileUnconfirmedHold(Order $order): float
    {
        $pendingHold = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->where('status', PaymentTransactionStatus::Pending)
            ->whereNotNull('yookassa_payment_id')
            ->latest()
            ->first();

        if (! $pendingHold) {
            return 0.0;
        }

        $info = $this->gateway->getPaymentInfo($pendingHold->yookassa_payment_id);

        if ($info->status !== 'waiting_for_capture') {
            return 0.0;
        }

        $amount = round((float) $pendingHold->amount, 2);

        $pendingHold->update(['status' => PaymentTransactionStatus::Succeeded]);
        $order->update(['held_amount' => $amount]);
        // MID-285: вебхук потерялся, реальный момент холда невосстановим —
        // фиксируем момент сверки, иначе окно доставки останется без начала.
        $this->markPaymentHeld($order);
        $this->publishOrderPaymentHeld($order);

        Log::warning('Recovered unconfirmed hold on assembly settle', [
            'order_id' => $order->id,
            'payment_id' => $pendingHold->yookassa_payment_id,
            'amount' => $amount,
        ]);

        return $amount;
    }

    /**
     * Сумма уже успешно собранных доплат (транзакции chargeExtra,
     * metadata.extra_charge = true). Основа retry-safe повтора списания.
     */
    private function extraChargedAmount(Order $order): float
    {
        return (float) $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->where('metadata->extra_charge', true)
            ->sum('amount');
    }

    /**
     * Технический сбой capture после успешной доплаты: только дедлайн (без
     * surcharge_amount и пуша) — страховка от вечного «полусписанного»
     * состояния. По истечении orders:cancel-expired-surcharge отменит заказ,
     * вернёт доплату рефандом и снимет холд (см. фикс refundOrReleaseHold).
     */
    private function markCaptureRetryPending(Order $order): void
    {
        if ($order->surcharge_expires_at !== null) {
            return;
        }

        $waitHours = app(\App\Services\Settings\ModuleConfigService::class)->getSurchargeWaitHours();

        $order->update(['surcharge_expires_at' => now()->addHours($waitHours)]);
    }

    /**
     * Пометить заказ «ожидает доплату»: сумма, дедлайн, событие для
     * платформы (админка/курьерка) и пуш клиенту.
     */
    private function markAwaitingSurcharge(Order $order, float $extra): void
    {
        $waitHours = app(\App\Services\Settings\ModuleConfigService::class)->getSurchargeWaitHours();

        $order->update([
            'surcharge_amount' => $extra,
            'surcharge_expires_at' => now()->addHours($waitHours),
        ]);

        $this->publishOrderSurchargeRequired($order->fresh());

        \App\Jobs\SendSurchargeRequiredNotification::dispatch($order->id);
    }

    private function clearSurcharge(Order $order): void
    {
        if ($order->surcharge_amount !== null || $order->surcharge_expires_at !== null) {
            $order->update(['surcharge_amount' => null, 'surcharge_expires_at' => null]);
        }
    }

    private function publishOrderSurchargeRequired(Order $order): void
    {
        $order->load('store');

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_surcharge_required',
            payload: [],
            shopCode: $order->shopCodeForEvents()
        );
    }

    /**
     * Повторная ссылка на оплату (если холд истёк/отменён).
     */
    public function retryPayment(Order $order, ?string $returnUrl = null): PaymentResult
    {
        // Товары 18+ в pickup-заказе запрещено оплачивать онлайн — только на
        // кассе при получении. Зеркалит проверку в CreateOrderAction: без неё
        // заказ, созданный с оплатой «на кассе», можно оплатить онлайн через /pay.
        if ($order->shipping_method === ShippingMethod::Pickup
            && $order->items()->whereHas('product', fn ($q) => $q->where('adult_only', true))->exists()) {
            return new PaymentResult(
                success: false,
                errorMessage: PaymentDeclineReason::message(PaymentDeclineReason::ADULT_ONLY_OFFLINE_PAYMENT),
                declineReason: PaymentDeclineReason::ADULT_ONLY_OFFLINE_PAYMENT,
            );
        }

        $paymentMethodId = $order->paymentCard?->payment_method_id;

        $result = $this->createHold($order, $paymentMethodId, $returnUrl);

        // Черновик (модуль draft_orders_until_payment): клиент начал оплату
        // заново — отсчёт TTL начинается заново, иначе крон отменил бы заказ
        // прямо во время оплаты по дедлайну прошлой попытки.
        if ($result->success && $order->status === OrderStatus::Draft) {
            $ttl = app(\App\Services\Settings\ModuleConfigService::class)->getDraftOrderTtlMinutes();
            $order->forceFill(['awaiting_payment_until' => now()->addMinutes($ttl)])->save();
        }

        return $result;
    }

    /**
     * Отменить холд (при отмене заказа).
     */
    public function cancelHold(Order $order): PaymentResult
    {
        $holdTransaction = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->whereIn('status', [PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded])
            ->latest()
            ->first();

        if (! $holdTransaction || ! $holdTransaction->yookassa_payment_id) {
            return new PaymentResult(success: true);
        }

        $result = $this->gateway->cancel($holdTransaction->yookassa_payment_id);

        if ($result->success) {
            $holdTransaction->update(['status' => PaymentTransactionStatus::Canceled]);
        }

        return $result;
    }

    /**
     * Возврат денег при отмене заказа.
     *
     * Если деньги уже списаны (captured) — рефанд невозвращённого остатка.
     * Если только захолдированы (held, capture ещё не было) — снятие холда.
     * При наличии captured холд НЕ снимаем: он уже закрыт capture'ом на стороне
     * YooKassa, и cancelHold зафейлился бы.
     *
     * Единая точка для CancelOrderAction (клиентская отмена), OrderEventTransformer
     * (внешняя отмена от курьерки/CRM) и AutoCancelPickupOrderAction.
     *
     * Флоу collector_payment: при отмене «полусписанного» заказа (captured =
     * только доплата chargeExtra, а capture холда упал/не успел) метод обязан
     * И вернуть доплату рефандом, И снять незакрытый холд. Для классического
     * флоу (captured = capture этого же холда) снятие холда пропускается —
     * поведение 1:1 прежнему.
     */
    public function refundOrReleaseHold(Order $order): PaymentResult
    {
        if ($order->payment_type !== PaymentType::Card) {
            return new PaymentResult(success: true);
        }

        $refundable = round(
            $this->recalcCapturedAmount($order) - (float) ($order->refunded_amount ?? 0),
            2
        );

        $refundResult = null;

        if ($refundable > 0.01) {
            $refundResult = $this->refundPayment($order, $refundable);
        }

        // Снять холд, если он остался НЕ закрытым capture'ом. В классическом флоу
        // captured > 0 означает capture этого же холда (cancelHold зафейлился бы) —
        // условие это сохраняет. В флоу collector_payment captured может состоять
        // только из доплаты (capture холда упал/не успел) — тогда холд открыт
        // и его надо вернуть покупателю.
        $openHold = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Hold)
            ->whereIn('status', [PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded])
            ->whereNotNull('yookassa_payment_id')
            ->latest()
            ->first();

        $holdCaptured = $openHold && $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->where('yookassa_payment_id', $openHold->yookassa_payment_id)
            ->exists();

        if ($openHold && ! $holdCaptured) {
            $cancelResult = $this->cancelHold($order);

            return $refundResult ?? $cancelResult;
        }

        return $refundResult ?? new PaymentResult(success: true);
    }

    /**
     * Сохранить карту из успешного платежа.
     */
    public function saveCardFromPayment(User $user, PaymentInfo $info): ?PaymentCard
    {
        if (! $info->paymentMethodId || ! $info->lastFour) {
            return null;
        }

        // Дедупликация по last_four + card_type (payment_method_id каждый раз новый)
        $existing = PaymentCard::where('user_id', $user->id)
            ->where('last_four', $info->lastFour)
            ->where('card_type', $info->cardType ?? 'unknown')
            ->first();

        if ($existing) {
            // Обновить payment_method_id на актуальный
            $existing->update(['payment_method_id' => $info->paymentMethodId]);

            return $existing;
        }

        $hasCards = PaymentCard::where('user_id', $user->id)->exists();

        return PaymentCard::create([
            'user_id' => $user->id,
            'payment_method_id' => $info->paymentMethodId,
            'card_type' => $info->cardType ?? 'unknown',
            'last_four' => $info->lastFour,
            'expiry_month' => $info->expiryMonth ?? '00',
            'expiry_year' => $info->expiryYear ?? '0000',
            'is_default' => ! $hasCards,
        ]);
    }

    /**
     * Привязка карты (холд 1₽ → сохранение → возврат).
     *
     * @param  string|null  $returnUrl  Адрес возврата клиента после подтверждения
     *                                  карты; null — дефолт из конфига.
     */
    public function bindCard(User $user, ?string $returnUrl = null): PaymentResult
    {
        $order = new Order;
        $order->id = 'bind-'.$user->id.'-'.uniqid();
        $order->total = 1;

        $result = $this->gateway->hold(
            $order,
            null,
            $this->receiptBuilder->forBind($user),
            returnUrl: $returnUrl,
        );

        if ($result->success) {
            PaymentTransaction::create([
                'order_id' => null,
                'type' => PaymentTransactionType::Hold,
                'status' => PaymentTransactionStatus::Pending,
                'amount' => 1.00,
                'yookassa_payment_id' => $result->paymentId,
                'metadata' => ['bind_user_id' => $user->id],
            ]);

            // Mock-режим привязку НЕ подтверждает: в этот момент клиент ещё не
            // ввёл карту, у ЮKassa нет её данных, и синтетическое подтверждение
            // только сняло бы холд без сохранения карты (MID-314). Карту
            // досохраняет вебхук или сверка списка карт — PaymentCardSync.
        }

        return $result;
    }

    /**
     * Актуальное состояние платежа в ЮKassa.
     *
     * Нужен там, где нельзя полагаться на вебхук: синхронизация черновика
     * заказа перед его отменой по TTL и поллинг статуса оплаты клиентом.
     */
    public function getPaymentState(string $yookassaPaymentId): PaymentInfo
    {
        return $this->gateway->getPaymentInfo($yookassaPaymentId);
    }

    /**
     * Mock-режим: подтвердить холд сразу, не дожидаясь вебхука.
     *
     * Переиспользует штатный обработчик — вместе с ним отрабатывает всё, что
     * висит на подтверждении оплаты: held_amount, финализация черновика заказа
     * и публикация события в курьерку. Карту в этот момент сохранить нельзя:
     * клиент её ещё не ввёл. Её досохраняет сверка списка карт
     * (PaymentCardSync), когда платёж реально оплачен.
     */
    private function mockConfirmHold(PaymentResult $result): void
    {
        if (! $result->success || $result->paymentId === null || ! PaymentMockMode::enabled()) {
            return;
        }

        Log::info('YooKassa mock: confirming hold without webhook', [
            'payment_id' => $result->paymentId,
        ]);

        $this->handleHoldSucceeded($result->paymentId);
    }

    /**
     * Mock-режим: подтвердить возврат сразу, не дожидаясь вебхука `refund.succeeded`.
     * Без него транзакция возврата навсегда осталась бы в pending, а
     * refunded_amount заказа не вырос бы.
     */
    private function mockConfirmRefund(PaymentResult $result, string $paymentId, float $amount): void
    {
        if (! $result->success || $result->refundId === null || ! PaymentMockMode::enabled()) {
            return;
        }

        Log::info('YooKassa mock: confirming refund without webhook', [
            'payment_id' => $paymentId,
            'refund_id' => $result->refundId,
            'amount' => $amount,
        ]);

        $this->handleRefundSucceeded($result->refundId, $paymentId, $amount);
    }

    /**
     * Обработка webhook: холд подтверждён (waiting_for_capture).
     */
    public function handleHoldSucceeded(string $yookassaPaymentId): void
    {
        // MID-285: заказ, который на момент холда уже уехал в курьерку (не
        // черновик), должен получить момент холда отдельным событием — payload
        // order.created для него уже улетел. Черновику событие не нужно: метка
        // попадёт в order.created при финализации ниже.
        $publishedOrder = null;

        DB::transaction(function () use ($yookassaPaymentId, &$publishedOrder) {
            $transaction = PaymentTransaction::where('yookassa_payment_id', $yookassaPaymentId)
                ->where('type', PaymentTransactionType::Hold)
                ->first();

            if (! $transaction) {
                Log::warning('handleHoldSucceeded: transaction not found', ['payment_id' => $yookassaPaymentId]);

                return;
            }

            // Привязку карты подтверждают и вебхук, и сверка списка карт
            // (PaymentCardSync), в том числе одновременно. Строку
            // блокируем, а уже снятый холд повторно не обрабатываем: иначе
            // карта сохранилась бы дважды, а холд снимался бы повторно.
            if (! $transaction->order_id && isset($transaction->metadata['bind_user_id'])) {
                $transaction = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->first();

                if ($transaction->status === PaymentTransactionStatus::Canceled) {
                    return;
                }
            }

            $transaction->update(['status' => PaymentTransactionStatus::Succeeded]);

            $info = $this->gateway->getPaymentInfo($yookassaPaymentId);

            $transaction->update(['payment_method_id' => $info->paymentMethodId]);

            if ($transaction->order_id) {
                $order = Order::find($transaction->order_id);
                if ($order) {
                    $order->update(['held_amount' => $transaction->amount]);
                    $this->markPaymentHeld($order);

                    if ($order->status !== OrderStatus::Draft) {
                        $publishedOrder = $order;
                    }

                    $card = $this->saveCardFromPayment($order->user, $info);
                    if ($card) {
                        $order->update(['payment_card_id' => $card->id]);
                    }
                }
            } else {
                // Привязка карты (order_id = null) — сохранить карту по user_id из metadata
                $bindUserId = $transaction->metadata['bind_user_id'] ?? null;
                if ($bindUserId) {
                    $user = User::find($bindUserId);
                    if ($user) {
                        $this->saveCardFromPayment($user, $info);

                        // Отменить холд 1₽ — вернуть деньги
                        $this->gateway->cancel($yookassaPaymentId);
                        $transaction->update(['status' => PaymentTransactionStatus::Canceled]);
                    }
                }
            }
        });

        // Черновик (модуль draft_orders_until_payment): подтверждение холда —
        // это и есть момент оформления заказа. Только теперь очищается корзина,
        // списывается промокод и событие уезжает в курьерку.
        $this->finalizeDraftOrderFor($yookassaPaymentId);

        if ($publishedOrder) {
            $this->publishOrderPaymentHeld($publishedOrder);
        }
    }

    /**
     * Довести черновик заказа до боевого состояния по подтверждённому холду.
     *
     * Вынесено из транзакции handleHoldSucceeded: финализация берёт свою
     * блокировку строки заказа и публикует событие в outbox.
     */
    private function finalizeDraftOrderFor(string $yookassaPaymentId): void
    {
        $order = $this->orderByPaymentId($yookassaPaymentId);

        if ($order && $order->status === OrderStatus::Draft) {
            app(\App\Actions\Order\FinalizeDraftOrderAction::class)->execute($order);
        }
    }

    private function orderByPaymentId(string $yookassaPaymentId): ?Order
    {
        $transaction = PaymentTransaction::where('yookassa_payment_id', $yookassaPaymentId)
            ->where('type', PaymentTransactionType::Hold)
            ->first();

        if (! $transaction || ! $transaction->order_id) {
            return null;
        }

        return Order::find($transaction->order_id);
    }

    /**
     * Обработка webhook: платёж подтверждён (после capture).
     */
    public function handlePaymentSucceeded(string $yookassaPaymentId): void
    {
        $transaction = PaymentTransaction::where('yookassa_payment_id', $yookassaPaymentId)
            ->where('type', PaymentTransactionType::Hold)
            ->first();

        if (! $transaction || ! $transaction->order_id) {
            return;
        }

        $order = Order::find($transaction->order_id);
        if (! $order || $order->is_paid) {
            return; // Уже обработано через capturePayment
        }

        // Capture произошёл на стороне YooKassa (ЛК, таймаут)
        $info = $this->gateway->getPaymentInfo($yookassaPaymentId);
        $amount = (float) $info->amount;

        DB::transaction(function () use ($order, $yookassaPaymentId, $amount) {
            // X-lock на заказ ПЕРВЫМ: параллельный capturePayment (settle-payment
            // сборщика) держит его до коммита, и проверка ниже видит уже
            // записанный capture вместо гонки insert → update (deadlock #837).
            $this->lockOrder($order);

            // Идемпотентность по КОНКРЕТНОМУ платежу: дубль capture этого же
            // payment_id не создаём. Проверка по любому capture была слишком
            // широкой — досписание (другой payment_id) блокировало бы запись
            // capture холда, и наоборот capture холда мог затереть captured_amount.
            if ($this->hasSucceededCapture($order, $yookassaPaymentId)) {
                // capture этого платежа уже учтён — только синхронизируем
                // captured_amount (на случай гонки с chargeExtra) и выходим.
                // Если заказ уже оплачен, capture записал capturePayment, пока
                // вебхук ждал блокировку, и чек поставлен им: FiscalizeSaleJob
                // создаёт строку чека асинхронно, поэтому проверка в
                // dispatchAtolSaleIfNeeded второй диспатч не отсекает. Чек здесь
                // ставим только для capture, после которого заказ не стал оплаченным.
                $wasPaid = (bool) $order->is_paid;

                $order->update([
                    'captured_amount' => $this->recalcCapturedAmount($order),
                    'is_paid' => true,
                ]);

                if (! $wasPaid) {
                    $this->dispatchAtolSaleIfNeeded($order);
                }

                return;
            }

            $captureTransaction = PaymentTransaction::create([
                'order_id' => $order->id,
                'type' => PaymentTransactionType::Capture,
                'status' => PaymentTransactionStatus::Succeeded,
                'amount' => $amount,
                'yookassa_payment_id' => $yookassaPaymentId,
            ]);

            $order->update([
                'captured_amount' => $this->recalcCapturedAmount($order),
                'is_paid' => true,
            ]);

            $this->publishOrderPaid($order);
            $this->dispatchAtolSaleIfNeeded($order);

            $this->receiptSync->syncForTransaction($captureTransaction);
        });
    }

    /**
     * Обработка webhook: холд отменён.
     */
    public function handleHoldCanceled(string $yookassaPaymentId, ?string $reason = null): void
    {
        $transaction = PaymentTransaction::where('yookassa_payment_id', $yookassaPaymentId)
            ->where('type', PaymentTransactionType::Hold)
            ->first();

        if ($transaction) {
            // Сохраняем машинный код причины (для идентификации клиентом) и
            // человекочитаемый текст. Если ЮKassa причину не прислала —
            // decline_reason остаётся null, сообщение — обобщённое.
            $reason = PaymentDeclineReason::normalize($reason);

            $transaction->update([
                'status' => PaymentTransactionStatus::Canceled,
                'error_message' => $reason !== null
                    ? PaymentDeclineReason::message($reason)
                    : 'Платёж отменён',
                'decline_reason' => $reason,
            ]);

            if ($transaction->order_id) {
                $order = Order::find($transaction->order_id);
                if ($order) {
                    // Черновик: заказа для платформы не существует, событие
                    // «ошибка оплаты» слать некому. Вместо этого закрываем
                    // черновик — возвращаем товар в остаток и резерв бонусов.
                    if ($order->status === OrderStatus::Draft) {
                        app(\App\Actions\Order\CancelDraftOrderAction::class)
                            ->execute($order, 'payment_canceled');
                    } else {
                        $this->publishOrderPaymentFailed($order);
                    }
                }
            }
        }
    }

    /**
     * Обработка webhook: refund succeeded.
     */
    public function handleRefundSucceeded(string $refundId, string $paymentId, float $amount): void
    {
        DB::transaction(function () use ($refundId, $paymentId, $amount) {
            $transaction = PaymentTransaction::where('yookassa_refund_id', $refundId)->first();

            if ($transaction) {
                $transaction->update(['status' => PaymentTransactionStatus::Succeeded]);
            }

            $order = Order::whereHas('paymentTransactions', function ($q) use ($paymentId) {
                $q->where('yookassa_payment_id', $paymentId);
            })->first();

            // ATOL: пробиваем свой чек возврата вместо синхронизации чека ЮKassa
            // (ЮKassa при ATOL-драйвере не является источником фискального чека).
            if (IntegrationConfigService::getDriver('fiscal') === 'atol') {
                if ($order) {
                    FiscalizeRefundJob::dispatch($order->id, $amount, $refundId);
                }
            } elseif ($transaction) {
                $this->receiptSync->syncForTransaction($transaction);
            }

            if ($order) {
                $order->update([
                    'refunded_amount' => (float) ($order->refunded_amount ?? 0) + $amount,
                ]);

                $this->publishOrderRefunded($order);
            }
        });
    }

    /**
     * X-lock на строку заказа с перечитыванием атрибутов в ТОТ ЖЕ экземпляр.
     *
     * Зачем блокировать: INSERT в payment_transactions берёт S-lock на строку
     * orders (FK order_id), а следующий UPDATE orders требует X-lock. Две такие
     * транзакции по одному заказу (capturePayment по settle-payment сборщика и
     * вебхук payment.succeeded, который ЮKassa шлёт через ~200 мс после capture)
     * держат по S-lock и обе ждут X-lock: InnoDB откатывает одну (GlitchTip
     * STOREFRONT_BACK-CV, #837), а при менее плотной гонке остаётся дубль
     * capture-строки по одному payment_id. X-lock первым оператором транзакции
     * сериализует их: вторая ждёт коммита первой и видит её capture.
     *
     * Зачем в тот же экземпляр, а не новый: вызывающие продолжают работать со
     * своим объектом (CreateOrderAction публикует order.created из него уже с
     * held_amount / payment_held_at), поэтому обновляем его как Model::refresh(),
     * только из-под блокировки.
     */
    private function lockOrder(Order $order): Order
    {
        $locked = $order->newQueryWithoutScopes()
            ->lockForUpdate()
            ->findOrFail($order->getKey());

        $order->setRawAttributes($locked->getAttributes(), true);

        return $order;
    }

    /**
     * Есть ли по заказу уже учтённый (succeeded) capture конкретного платежа.
     * Вызывать под lockOrder(): capturePayment и вебхук payment.succeeded по
     * одному платежу сериализуются блокировкой заказа, и кто пришёл вторым,
     * видит capture первого.
     */
    private function hasSucceededCapture(Order $order, string $yookassaPaymentId): bool
    {
        return $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->where('yookassa_payment_id', $yookassaPaymentId)
            ->exists();
    }

    /**
     * Реально захваченная по заказу сумма = сумма уникальных succeeded
     * capture-транзакций по payment_id. Дубли одного платежа (capturePayment +
     * webhook payment.succeeded по одному PID) схлопываются в один захват
     * (max по группе), разные платежи (холд + досписание) складываются.
     *
     * Устойчивый источник истины для captured_amount вместо присваивания/
     * аккумуляции, которые рассинхронизировались при гонке webhook'а (заказ #345:
     * досписание 294.69 терялось из captured_amount → не попадало в возврат).
     */
    private function recalcCapturedAmount(Order $order): float
    {
        $captured = $order->paymentTransactions()
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->get()
            ->groupBy('yookassa_payment_id')
            ->sum(fn ($group) => (float) $group->max('amount'));

        return round((float) $captured, 2);
    }

    private function publishOrderPaid(Order $order): void
    {
        $order->load('store');

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_paid',
            payload: [],
            shopCode: $order->shopCodeForEvents()
        );
    }

    /**
     * MID-285: зафиксировать момент холдирования средств по заказу.
     *
     * Идемпотентно: первый успешный холд задаёт точку отсчёта окна доставки,
     * повторные подтверждения (ретрай вебхука, сверка, доплата) её не двигают —
     * иначе окно у сборщика и курьера уезжало бы вперёд после каждого платежа.
     */
    private function markPaymentHeld(Order $order): void
    {
        if ($order->payment_held_at !== null) {
            return;
        }

        $order->update(['payment_held_at' => now()]);
    }

    /**
     * MID-285: сообщить платформе момент холдирования средств.
     *
     * Обычные изменения заказа витрина не публикует (rabbitmq-events.orders
     * = ['created']), поэтому момент холда по уже уехавшему заказу доедет
     * только кастомным событием.
     */
    private function publishOrderPaymentHeld(Order $order): void
    {
        $order->load('store');

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_payment_held',
            payload: [],
            shopCode: $order->store->code
        );
    }

    private function publishOrderPaymentFailed(Order $order): void
    {
        $order->load('store');

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_payment_failed',
            payload: [],
            shopCode: $order->shopCodeForEvents()
        );
    }

    private function publishOrderRefunded(Order $order): void
    {
        $order->load('store');

        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $order,
            eventType: 'order_refunded',
            payload: [],
            shopCode: $order->shopCodeForEvents()
        );
    }

    /**
     * Диспатч ATOL sale-чека после успешного online (card) capture.
     *
     * Только когда активный фискализатор — ATOL: ЮKassa пробивает чек прихода
     * сама, внутри платежа (см. ReceiptBuilder), и повторный чек здесь не нужен.
     * Идемпотентно: если по заказу уже есть payment-чек — не диспатчим повторно
     * (защита от гонки capturePayment + webhook payment.succeeded по одному заказу).
     */
    private function dispatchAtolSaleIfNeeded(Order $order): void
    {
        if (IntegrationConfigService::getDriver('fiscal') !== 'atol') {
            return;
        }

        if ($order->receipts()->where('type', 'payment')->exists()) {
            return;
        }

        FiscalizeSaleJob::dispatch(
            $order->id,
            PaymentType::Card->value,
            (float) ($order->captured_amount ?? $order->total),
        );
    }

    // ================================================================
    //  Consolidated payment flow (marketplace mode).
    //  Инвариант I-3: методы выше НЕ трогаем. Всё ниже — отдельный
    //  code path под marketplace client-order'а (M.9=a, M.10=b).
    //  См. docs/design-analysis/marketplace-model.md §5.1
    // ================================================================

    /**
     * Создать один YooKassa-холд на суммарную сумму marketplace client-order'а.
     *
     * @param  array<int, Order>  $orders  Sub-orders, уже сохранённые в БД с client_order_id
     */
    public function createConsolidatedHold(
        array $orders,
        float $totalAmount,
        string $clientOrderId,
        ?string $paymentMethodId = null,
        ?string $returnUrl = null,
    ): PaymentResult {
        if ($orders === []) {
            return new PaymentResult(success: false, errorMessage: 'Нет sub-orders для consolidated hold');
        }

        // Anchor — первый sub-order. Временная подмена total'а нужна,
        // потому что YooKassaService::hold() берёт amount из $order->total.
        // Оригинальное значение восстанавливаем в finally — DB не трогается.
        $anchor = $orders[0];
        $originalTotal = $anchor->total;
        $anchor->total = round($totalAmount, 2);

        // Чек строим по позициям ВСЕХ sub-orders, распределяя на полную сумму
        // client-order'а — иначе чек anchor'а не сойдётся с суммой холда.
        $receipt = $this->receiptBuilder->forClientOrder($orders, $totalAmount);

        try {
            $result = $this->gateway->hold($anchor, $paymentMethodId, $receipt, returnUrl: $returnUrl);
        } finally {
            $anchor->total = $originalTotal;
        }

        $holdResult = DB::transaction(function () use ($result, $totalAmount, $clientOrderId, $paymentMethodId, $orders) {
            PaymentTransaction::create([
                'order_id' => null,
                'client_order_id' => $clientOrderId,
                'type' => PaymentTransactionType::Hold,
                'status' => $result->success ? PaymentTransactionStatus::Pending : PaymentTransactionStatus::Canceled,
                'amount' => $totalAmount,
                'yookassa_payment_id' => $result->paymentId,
                'payment_method_id' => $result->paymentMethodId ?? $paymentMethodId,
                'error_message' => $result->errorMessage,
                'decline_reason' => $result->declineReason,
            ]);

            if (! $result->success) {
                return $result;
            }

            $card = null;
            if ($paymentMethodId) {
                $card = PaymentCard::where('payment_method_id', $paymentMethodId)->first();
            }

            foreach ($orders as $order) {
                $order->update([
                    'payment_type' => PaymentType::Card,
                    'payment_card_id' => $card?->id ?? $order->payment_card_id,
                    'held_amount' => (float) $order->total,
                ]);
                // MID-285: клиентский заказ маркетплейса — held_amount проставляется
                // сразу всем sub-order'ам, момент холда фиксируем тем же шагом.
                // handleHoldSucceeded сюда не дойдёт: у consolidated-транзакции
                // order_id = null, и заказы по ней он не находит.
                $this->markPaymentHeld($order);
            }

            return $result;
        });

        // Вебхука `payment.waiting_for_capture` на стенде нет, а captureClientOrder
        // ищет ТОЛЬКО подтверждённый холд — без этого списание по client-order'у
        // в mock-режиме было бы невозможно.
        $this->mockConfirmHold($holdResult);

        return $holdResult;
    }

    /**
     * Подтвердить consolidated hold и распределить capture по sub-orders.
     */
    public function captureClientOrder(string $clientOrderId, float $totalAmount): PaymentResult
    {
        $holdTransaction = PaymentTransaction::query()
            ->where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Hold)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->latest('id')
            ->first();

        if (! $holdTransaction || ! $holdTransaction->yookassa_payment_id) {
            Log::warning('captureClientOrder: no active consolidated hold', ['client_order_id' => $clientOrderId]);

            return new PaymentResult(success: false, errorMessage: 'Нет активного консолидированного холда');
        }

        // Загружаем sub-orders ДО capture — нужны для построения чека на сумму списания.
        $subOrders = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->get();

        $result = $this->gateway->capture(
            $holdTransaction->yookassa_payment_id,
            $totalAmount,
            $this->receiptBuilder->forClientOrder($subOrders->all(), $totalAmount),
        );

        return DB::transaction(function () use ($clientOrderId, $totalAmount, $result, $holdTransaction, $subOrders) {
            PaymentTransaction::create([
                'order_id' => null,
                'client_order_id' => $clientOrderId,
                'type' => PaymentTransactionType::Capture,
                'status' => $result->success ? PaymentTransactionStatus::Succeeded : PaymentTransactionStatus::Canceled,
                'amount' => $totalAmount,
                'yookassa_payment_id' => $holdTransaction->yookassa_payment_id,
                'error_message' => $result->errorMessage,
            ]);

            if (! $result->success) {
                return $result;
            }

            foreach ($subOrders as $order) {
                $order->update([
                    'captured_amount' => (float) $order->total,
                    'is_paid' => true,
                ]);

                $this->publishOrderPaid($order);
                $this->dispatchAtolSaleIfNeeded($order);
            }

            return $result;
        });
    }

    /**
     * Отменить consolidated hold (при отмене всего client-order'а до capture).
     */
    public function cancelConsolidatedHold(string $clientOrderId): PaymentResult
    {
        $holdTransaction = PaymentTransaction::query()
            ->where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Hold)
            ->whereIn('status', [PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded])
            ->latest('id')
            ->first();

        if (! $holdTransaction || ! $holdTransaction->yookassa_payment_id) {
            return new PaymentResult(success: true);
        }

        $result = $this->gateway->cancel($holdTransaction->yookassa_payment_id);

        if ($result->success) {
            $holdTransaction->update(['status' => PaymentTransactionStatus::Canceled]);
        }

        return $result;
    }

    /**
     * Возврат с consolidated hold/capture.
     */
    public function refundClientOrder(string $clientOrderId, float $amount): PaymentResult
    {
        $captureTransaction = PaymentTransaction::query()
            ->where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->latest('id')
            ->first();

        if (! $captureTransaction || ! $captureTransaction->yookassa_payment_id) {
            return new PaymentResult(success: false, errorMessage: 'Нет успешного списания для возврата');
        }

        $alreadyRefunded = (float) PaymentTransaction::query()
            ->where('client_order_id', $clientOrderId)
            ->where('type', PaymentTransactionType::Refund)
            ->whereIn('status', [PaymentTransactionStatus::Pending, PaymentTransactionStatus::Succeeded])
            ->sum('amount');

        $maxRefundable = (float) $captureTransaction->amount - $alreadyRefunded;

        if ($amount > $maxRefundable) {
            return new PaymentResult(
                success: false,
                errorMessage: "Максимальная сумма возврата: {$maxRefundable}",
            );
        }

        $subOrders = Order::query()
            ->where('client_order_id', $clientOrderId)
            ->get();

        $result = $this->gateway->refund(
            $captureTransaction->yookassa_payment_id,
            $amount,
            $this->receiptBuilder->forClientOrderRefund($subOrders->all(), $amount),
        );

        DB::transaction(function () use ($clientOrderId, $amount, $result, $captureTransaction) {
            PaymentTransaction::create([
                'order_id' => null,
                'client_order_id' => $clientOrderId,
                'type' => PaymentTransactionType::Refund,
                'status' => $result->success ? PaymentTransactionStatus::Pending : PaymentTransactionStatus::Canceled,
                'amount' => $amount,
                'yookassa_payment_id' => $captureTransaction->yookassa_payment_id,
                'yookassa_refund_id' => $result->refundId,
                'error_message' => $result->errorMessage,
            ]);
        });

        return $result;
    }
}
