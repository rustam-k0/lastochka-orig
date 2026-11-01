<?php

namespace App\GraphQL\Queries\Shop;

use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Nuwave\Lighthouse\Exceptions\ValidationException;

/**
 * Статистика магазина для вкладки «Статистика» (раздел «Магазины»).
 *
 * Выручка — по заказам в статусе 5 (FINISHED), период считается по полю
 * orders.finished_at. Количество заказов — по created_at, кроме отменённых (6).
 * Активные заказы — снимок по статусам на текущий момент.
 *
 * ВАЖНО: во все агрегаты попадают ТОЛЬКО корневые заказы (parent_order_id IS
 * NULL). При сплите по зонам сборки дочерние подзаказы дублировали бы выручку
 * и счётчики (тот же принцип, что в scopeAdmin).
 */
final class ShopStatistics
{
    /** Максимальная длина произвольного периода в днях («ограничить квартал»). */
    private const MAX_PERIOD_DAYS = 92;

    public function __invoke($_, array $args): array
    {
        $shopId = (int) $args['shop_id'];
        $now = Carbon::now();

        $month = $args['month'] ?? $now->format('Y-m');
        $this->validateMonth($month);
        [$monthStart, $monthEnd] = $this->monthBounds($month);

        [$periodStart, $periodEnd] = $this->periodBounds(
            $args['period_from'] ?? null,
            $args['period_to'] ?? null
        );

        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();

        return [
            'revenue_today' => $this->revenueBlock($shopId, $todayStart, $todayEnd),
            'revenue_month' => $this->revenueBlock($shopId, $monthStart, $monthEnd),
            'revenue_period' => $periodStart
                ? $this->revenueBlock($shopId, $periodStart, $periodEnd)
                : null,
            'orders_today' => $this->ordersCount($shopId, $todayStart, $todayEnd),
            'orders_month' => $this->ordersCount($shopId, $monthStart, $monthEnd),
            'orders_period' => $periodStart
                ? $this->ordersCount($shopId, $periodStart, $periodEnd)
                : null,
            'active_orders' => $this->activeOrders($shopId, $todayEnd),
        ];
    }

    /** Базовый запрос: только корневые заказы конкретного магазина. */
    private function baseQuery(int $shopId): Builder
    {
        return Order::query()
            ->whereNull('parent_order_id')
            ->where('shop_id', $shopId);
    }

    /** Выручка: SUM(amount) + COUNT по завершённым (5) заказам за интервал finished_at. */
    private function revenueBlock(int $shopId, Carbon $start, Carbon $end): array
    {
        $query = $this->baseQuery($shopId)
            ->where('status_id', Status::ORDER_FINISHED)
            ->whereBetween('finished_at', [$start, $end]);

        return [
            'sum' => (float) $query->sum('amount'),
            'orders_count' => $query->count(),
        ];
    }

    /** Количество оформленных заказов за интервал created_at, кроме отменённых (6). */
    private function ordersCount(int $shopId, Carbon $start, Carbon $end): int
    {
        return $this->baseQuery($shopId)
            ->where('status_id', '!=', Status::ORDER_CANCELED)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /** Снимок активных заказов по этапам обработки. */
    private function activeOrders(int $shopId, Carbon $todayEnd): array
    {
        return [
            // Слот ещё не наступил — сборщику не виден (ср. scopeDaily в delivery_back).
            'new' => $this->baseQuery($shopId)
                ->where('status_id', Status::ORDER_CREATED)
                ->where('slot_start_date', '>', $todayEnd)
                ->count(),
            // Слот наступил (или отсутствует) — заказ доступен сборщику.
            'waiting_collector' => $this->baseQuery($shopId)
                ->where('status_id', Status::ORDER_CREATED)
                ->where(function (Builder $q) use ($todayEnd) {
                    $q->whereNull('slot_start_date')
                        ->orWhere('slot_start_date', '<=', $todayEnd);
                })
                ->count(),
            'assembling' => $this->baseQuery($shopId)
                ->where('status_id', Status::ORDER_PREPARING)
                ->count(),
            // Самовывоз в доставочные карточки не попадает: после сборки он
            // ждёт выдачи, этапов «Готов к доставке»/«В доставке» у него
            // нет (MID-146). До сборки считается как обычный заказ.
            'ready_for_delivery' => $this->baseQuery($shopId)
                ->where('status_id', Status::ORDER_ASSEMBLED)
                ->where('type_id', '!=', Type::RECEIVE_PICKUP)
                ->count(),
            'in_delivery' => $this->baseQuery($shopId)
                ->where('status_id', Status::ORDER_IN_DELIVERY)
                ->where('type_id', '!=', Type::RECEIVE_PICKUP)
                ->count(),
        ];
    }

    private function validateMonth(string $month): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw ValidationException::withMessages([
                'month' => ['Месяц должен быть в формате YYYY-MM.'],
            ]);
        }

        $monthNum = (int) mb_substr($month, 5, 2);
        if ($monthNum < 1 || $monthNum > 12) {
            throw ValidationException::withMessages([
                'month' => ['Некорректный номер месяца (ожидается 01–12).'],
            ]);
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /**
     * Границы произвольного периода. Обе границы обязательны вместе; если ни одна
     * не передана — период не считается (возвращается [null, null]).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function periodBounds($from, $to): array
    {
        $hasFrom = $from !== null;
        $hasTo = $to !== null;

        if ($hasFrom !== $hasTo) {
            throw ValidationException::withMessages([
                'period' => ['Нужно указать обе границы периода: period_from и period_to.'],
            ]);
        }

        if (! $hasFrom) {
            return [null, null];
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        if ($start->gt($end)) {
            throw ValidationException::withMessages([
                'period_from' => ['Дата начала периода не может быть больше даты конца.'],
            ]);
        }

        if ($start->diffInDays($end) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'period' => ['Период не может превышать ' . self::MAX_PERIOD_DAYS . ' дня.'],
            ]);
        }

        return [$start, $end];
    }
}
