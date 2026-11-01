<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\Order\OrderCancelled;
use App\Exceptions\OrderCannotBeCancelledException;
use App\Models\Order;
use App\RabbitMQ\Debug\LoggingEventPublisher;
use App\Services\Bonus\BonusService;
use App\Services\Settings\ModuleConfigService;
use App\Services\YandexDelivery\YandexClaimService;
use Illuminate\Support\Facades\DB;

final class CancelOrderAction
{
    public function __construct(
        private ReleaseProductsAction $releaseProducts,
        private \App\Services\Payment\PaymentService $paymentService,
        private YandexClaimService $yandexClaimService,
        private BonusService $bonusService,
    ) {}

    /**
     * Отменяет заказ и возвращает товары на склад.
     *
     * Router:
     *  - черновик неоплаченного заказа (модуль draft_orders_until_payment) →
     *    CancelDraftOrderAction: платформа о заказе не знает, событие отмены
     *    ей отправлять нельзя, корзину трогать не нужно
     *  - marketplace + sub-order (`client_order_id !== null`) → cancelSubOrder()
     *  - иначе → cancelStandard() (байт-в-байт старое поведение)
     *
     * @throws OrderCannotBeCancelledException
     */
    public function execute(Order $order): Order
    {
        if ($order->status === OrderStatus::Draft) {
            return app(CancelDraftOrderAction::class)->execute($order, 'client_cancelled');
        }

        $moduleConfig = app(ModuleConfigService::class);

        if ($moduleConfig->isMarketplaceMode() && $order->client_order_id !== null) {
            return $this->cancelSubOrder($order);
        }

        return $this->cancelStandard($order);
    }

    /**
     * Стандартное поведение отмены — байт-в-байт оригинал execute().
     *
     * ВНИМАНИЕ: инвариант I-3 (refactor-rename zero-change).
     * Тело этого метода НЕ МЕНЯТЬ без отдельного PR с тестами в форках
     * Aladdin/Lastochka/Foodlink/Cron. Любая правка должна идти через
     * router execute() либо через cancelSubOrder() для marketplace-ветки.
     *
     * @throws OrderCannotBeCancelledException
     */
    private function cancelStandard(Order $order): Order
    {
        if (! $order->status->canCancel()) {
            throw new OrderCannotBeCancelledException($order->status);
        }

        $yandexClaim = $order->yandexClaim;
        if ($yandexClaim && $yandexClaim->status->isPickupArrivedOrLater()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'order' => ['Отмена невозможна — курьер Яндекс уже прибыл'],
            ]);
        }

        return DB::transaction(function () use ($order, $yandexClaim) {
            // Загрузить store для публикации события
            $order->load('store');

            // Вернуть товары на склад
            $this->releaseProducts->execute($order);

            // Возврат бонусов: spent → 0, отмена начисленных (если были)
            $this->bonusService->refundAll($order);

            // Обновить статус
            $order->update(['status' => OrderStatus::Cancelled]);

            // Отменить заявку Яндекс Доставки если есть
            if ($yandexClaim) {
                $this->yandexClaimService->cancelClaim($yandexClaim);
            }

            // Отправить событие
            event(new OrderCancelled($order));

            // Опубликовать событие order.order_cancelled в RabbitMQ
            LoggingEventPublisher::dispatch(
                entityType: 'orders',
                model: $order,
                eventType: 'order_cancelled',
                payload: [],
                shopCode: $order->shopCodeForEvents()
            );

            // Вернуть деньги при отмене: списанные (captured) — рефандом, захолдированные —
            // снятием холда. Best-effort: сбой платёжного шлюза НЕ откатывает отмену заказа
            // (товары/бонусы/статус уже применены), возврат до-инициируется ретраем/вручную.
            if ($order->payment_type === \App\Enums\PaymentType::Card) {
                try {
                    $this->paymentService->refundOrReleaseHold($order);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Refund/hold-release failed on order cancellation', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $order->fresh();
        });
    }

    /**
     * Отмена sub-order'а marketplace client_order'а.
     *
     * Правила (см. marketplace-model.md §5.1 M.14):
     *  - бонусы НЕ трогаем — агрегированный refund/cancel произойдёт только
     *    когда весь client_order финализируется (все sub-orders delivered/cancelled)
     *  - consolidated hold НЕ отменяем — он общий, отменится на уровне
     *    CancelClientOrderAction / FinalizeClientOrderAction (P2.6)
     *  - stock возвращаем, статус обновляем, event+RabbitMQ публикуем,
     *    yandex claim отменяем — как в standard
     *
     * @throws OrderCannotBeCancelledException
     */
    private function cancelSubOrder(Order $order): Order
    {
        if (! $order->status->canCancel()) {
            throw new OrderCannotBeCancelledException($order->status);
        }

        $yandexClaim = $order->yandexClaim;
        if ($yandexClaim && $yandexClaim->status->isPickupArrivedOrLater()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'order' => ['Отмена невозможна — курьер Яндекс уже прибыл'],
            ]);
        }

        $cancelled = DB::transaction(function () use ($order, $yandexClaim) {
            $order->load('store');

            $this->releaseProducts->execute($order);

            $order->update(['status' => OrderStatus::Cancelled]);

            if ($yandexClaim) {
                $this->yandexClaimService->cancelClaim($yandexClaim);
            }

            event(new OrderCancelled($order));

            LoggingEventPublisher::dispatch(
                entityType: 'orders',
                model: $order,
                eventType: 'order_cancelled',
                payload: [],
                shopCode: $order->shopCodeForEvents()
            );

            return $order->fresh();
        });

        // После отмены sub-order'а — если все sub-orders client_order'а в
        // terminal состоянии (delivered/cancelled), весь client_order готов
        // к финализации (aggregated bonus + consolidated hold release/refund).
        // Сам finalize реализует P2.6 (FinalizeClientOrderAction). Пока только
        // логируем детект, чтобы aggregation не пропал при ручном cascade cancel.
        $this->maybeFinalizeClientOrder((string) $order->client_order_id);

        return $cancelled;
    }

    /**
     * Финализация client_order'а после отмены sub-order'а.
     *
     * Покрывает кейс «оператор отменил все sub-orders по одному через админку»,
     * который обходит CancelClientOrderAction. FinalizeClientOrderAction сам
     * идемпотентен (через orders.client_order_finalized_at) и проверяет что
     * все sub-orders в terminal state, прежде чем делать consolidated payment
     * ops — безопасно вызывать из любого хука.
     *
     * Defensive: ловим любую ошибку финализации и просто логируем. Sub-order
     * уже отменён в outer DB::transaction, ничего откатывать нельзя. Повторный
     * вызов (из OrderEventTransformer::handleDeliveredSubOrder или следующего
     * cancelSubOrder) поймает пропущенное состояние за счёт идемпотентности.
     */
    private function maybeFinalizeClientOrder(string $clientOrderId): void
    {
        try {
            app(FinalizeClientOrderAction::class)->execute($clientOrderId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Deferred finalize failed after sub-order cancel', [
                'client_order_id' => $clientOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
