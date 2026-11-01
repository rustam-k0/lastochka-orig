<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\BonusTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonus\BonusService;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Полная отмена клиентского заказа пользователем (marketplace mode).
 *
 * Контракт (см. docs/design-analysis/marketplace-model.md §5.1 M.14):
 *  - проходит по всем sub-orders и вызывает CancelOrderAction (cancelSubOrder path,
 *    bonuses не трогает)
 *  - освобождает бонусные резервы sub-orders (BonusHold + users.bonus_reserved) —
 *    отмена до сборки возвращает резерв, а не создаёт фантомный refund
 *  - агрегированный refund ФАКТИЧЕСКИ списанных бонусов (по bonus_transactions,
 *    не по зеркальному orders.bonus_spent) — одной транзакцией на client_order
 *    (order_id = null, client_order_id = текущий uuid)
 *  - consolidated YooKassa hold отменяется один раз на весь client_order через
 *    PaymentService::cancelConsolidatedHold (приходит из P2.1)
 *
 * Авторизация: sub-orders фильтруются по `user_id`, чужой client_order_id даст
 * пустую коллекцию → NotFoundHttpException. Middleware `auth:sanctum` гарантирует,
 * что $user заполнен, middleware `marketplace.mode` — что роут доступен только
 * в marketplace режиме (иначе abort(404)).
 */
final class CancelClientOrderAction
{
    public function __construct(
        private CancelOrderAction $cancelOrderAction,
        private PaymentService $paymentService,
        private BonusService $bonusService,
    ) {}

    /**
     * @throws NotFoundHttpException
     */
    public function execute(string $clientOrderId, User $user): void
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('client_order_id', $clientOrderId)
            ->get();

        if ($orders->isEmpty()) {
            throw new NotFoundHttpException('Client order not found');
        }

        DB::transaction(function () use ($orders, $clientOrderId, $user): void {
            // 1. Отмена каждого sub-order'а через CancelOrderAction.
            //    В marketplace режиме роутер execute() уйдёт в cancelSubOrder(),
            //    который НЕ трогает bonus transactions — агрегированный refund
            //    сделаем ниже одной транзакцией.
            foreach ($orders as $subOrder) {
                if (! ($subOrder->status instanceof OrderStatus ? $subOrder->status : OrderStatus::from((string) $subOrder->status))->canCancel()) {
                    // Sub-order уже в terminal статусе (delivered/cancelled) — пропускаем,
                    // но продолжаем, чтобы хотя бы отменить то что можно.
                    continue;
                }

                $this->cancelOrderAction->execute($subOrder);
            }

            // 2. Освободить бонусные резервы sub-orders (отмена ДО сборки):
            //    фактического списания ещё не было — существует только BonusHold +
            //    users.bonus_reserved. releaseHolds идемпотентен (повторный DELETE — no-op).
            foreach ($orders as $subOrder) {
                $this->bonusService->releaseHolds($subOrder);
            }

            // 3. Агрегированный refund ФАКТИЧЕСКИ списанных бонусов — одной транзакцией
            //    на client_order. orders.bonus_spent — зеркальный снапшот (до сборки он
            //    означает «зарезервировано», а не «списано»), поэтому сумма возврата
            //    считается по bonus_transactions: Σ spend(online) − Σ refund(online)
            //    (зеркало BonusService::currentSpentForOrder на уровне client_order).
            //    Идемпотентность: если refund уже есть (например, повторный DELETE), пропускаем.
            $orderIds = $orders->pluck('id');
            $actuallySpent = (int) BonusTransaction::query()
                ->whereIn('order_id', $orderIds)
                ->where('type', 'spend')
                ->where('source', 'online')
                ->sum('amount');
            $alreadyRefundedPerOrder = (int) BonusTransaction::query()
                ->whereIn('order_id', $orderIds)
                ->where('type', 'refund')
                ->where('source', 'online')
                ->sum('amount');
            $totalSpent = max(0, $actuallySpent - $alreadyRefundedPerOrder);

            if ($totalSpent > 0) {
                $alreadyRefunded = BonusTransaction::query()
                    ->where('client_order_id', $clientOrderId)
                    ->where('type', 'refund')
                    ->exists();

                if (! $alreadyRefunded) {
                    $locked = User::lockForUpdate()->findOrFail($user->id);
                    $locked->bonus_balance += $totalSpent;
                    $locked->save();

                    BonusTransaction::create([
                        'user_id' => $locked->id,
                        'order_id' => null,
                        'client_order_id' => $clientOrderId,
                        'type' => 'refund',
                        'amount' => $totalSpent,
                        'balance_after' => $locked->bonus_balance,
                        'description' => "Возврат за отменённый клиентский заказ {$clientOrderId}",
                        'created_at' => now(),
                    ]);
                }
            }

            // 4. Отмена consolidated hold (если есть).
            //    Все sub-orders делят один hold (P2.1), достаточно вызвать cancel один раз.
            //    cancelConsolidatedHold сам идемпотентен (возвращает success:true если холда нет).
            $firstOrder = $orders->first();
            if (
                $firstOrder
                && $firstOrder->payment_type === PaymentType::Card
                && (int) ($firstOrder->held_amount ?? 0) > 0
                && (int) ($firstOrder->captured_amount ?? 0) === 0
            ) {
                $this->paymentService->cancelConsolidatedHold($clientOrderId);
            }
        });
    }
}
