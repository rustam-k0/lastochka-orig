<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Bonus\BonusService;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Отменяет черновик заказа, оплата которого так и не подтвердилась
 * (модуль draft_orders_until_payment).
 *
 * Отличия от обычной отмены (CancelOrderAction):
 *  - НЕ публикует order_cancelled: платформа о заказе не знает, событие ушло бы
 *    в пустоту и оставило бы «Entity not found» в логах консьюмера курьерки;
 *  - НЕ трогает корзину: черновик её не очищал, клиент возвращается к товарам;
 *  - awaiting_payment_until намеренно НЕ обнуляется — это маркер «заказ умер
 *    черновиком», по нему scopeVisibleToClient прячет его из истории клиента.
 *
 * Возвращает товар в остаток и освобождает резерв бонусов, снимает холд.
 * Идемпотентен: работает только со статусом draft под блокировкой строки.
 */
final class CancelDraftOrderAction
{
    public function __construct(
        private readonly ReleaseProductsAction $releaseProducts,
        private readonly BonusService $bonusService,
        private readonly PaymentService $paymentService,
    ) {}

    public function execute(Order $order, string $reason = 'payment_not_confirmed'): Order
    {
        if ($order->status !== OrderStatus::Draft) {
            return $order;
        }

        [$cancelled, $changed] = DB::transaction(function () use ($order) {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return [$order, false];
            }

            // Гонка с финализацией: оплата успела подтвердиться — заказ уже
            // боевой, отменять его здесь нельзя.
            if ($locked->status !== OrderStatus::Draft) {
                return [$locked, false];
            }

            $this->releaseProducts->execute($locked);
            $this->bonusService->refundAll($locked);

            $locked->forceFill(['status' => OrderStatus::Cancelled])->save();

            return [$locked->fresh(), true];
        });

        if (! $changed) {
            return $cancelled;
        }

        // Снятие холда — best-effort и вне транзакции: неподтверждённый платёж
        // ЮKassa отменить через API нельзя (400 «Payment doesn't exist»), он
        // протухнет сам. Сбой шлюза не должен откатывать возврат товара.
        try {
            $this->paymentService->cancelHold($cancelled);
        } catch (\Throwable $e) {
            Log::info('Draft order hold release skipped', [
                'order_id' => $cancelled->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Draft order cancelled', [
            'order_id' => $cancelled->id,
            'user_id' => $cancelled->user_id,
            'reason' => $reason,
        ]);

        return $cancelled;
    }
}
