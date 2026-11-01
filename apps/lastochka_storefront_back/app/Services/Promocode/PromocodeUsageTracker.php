<?php

declare(strict_types=1);

namespace App\Services\Promocode;

use App\Models\Order;
use App\Models\Promocode;
use App\Models\PromocodeUsage;

/**
 * Фиксация использования промокода: запись в promocode_usages + increment used_count.
 *
 * Момент фиксации — оформление заказа, а не применение промокода к корзине:
 * отказ от оформления не должен «сжигать» попытку. Отмена заказа промокод
 * возвращает: лимиты max_uses и max_uses_per_client считают только заказы,
 * которые не отменены ({@see PromocodeUsage::scopeActive()}). used_count —
 * счётчик всех оформлений, в лимитах не участвует.
 *
 * Для черновика (модуль draft_orders_until_payment) оформлением считается
 * подтверждение оплаты, поэтому вызов переехал из CreateOrderAction в
 * FinalizeDraftOrderAction — иначе брошенная страница ЮKassa сжигала бы промокод.
 */
final class PromocodeUsageTracker
{
    public function track(Order $order, ?Promocode $promocode): void
    {
        if (! $promocode) {
            return;
        }

        // Гонка вебхука и клиентского поллинга: оба могут финализировать
        // черновик, попытка должна списаться один раз.
        if (PromocodeUsage::where('order_id', $order->id)->exists()) {
            return;
        }

        PromocodeUsage::create([
            'promocode_id' => $promocode->id,
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'used_at' => now(),
        ]);

        Promocode::where('id', $promocode->id)->increment('used_count');
    }

    /**
     * Фиксация по снапшоту заказа — когда исходной модели промокода на руках
     * уже нет (финализация черновика в вебхуке/кроне).
     */
    public function trackFromSnapshot(Order $order): void
    {
        $snapshot = $order->promocode()->first();

        if (! $snapshot || ! $snapshot->original_id) {
            return;
        }

        $this->track($order, Promocode::find($snapshot->original_id));
    }
}
