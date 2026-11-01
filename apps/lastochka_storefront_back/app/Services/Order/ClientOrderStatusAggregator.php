<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Enums\OrderStatus;

/**
 * Консолидация статусов sub-orders marketplace client_order'а по правилу "минимальный прогресс".
 *
 * Решение M.11=a (см. docs/design-analysis/marketplace-model.md §5.1):
 * - пустой массив → Pending (client_order создан, sub-orders ещё нет)
 * - все Cancelled → Cancelled
 * - иначе → минимум по progress-шкале среди non-cancelled:
 *   Pending < Assembling < Assembled < InDelivery < Delivered
 *
 * Cancelled sub-orders при наличии хотя бы одного non-cancelled игнорируются
 * (частичная отмена не должна "откатывать" статус всего заказа).
 *
 * Источник: логика извлечена байт-в-байт из {@see \App\Actions\Order\CreateClientOrderAction::consolidateStatuses}
 * в рамках инварианта I-3 (refactor-rename без изменения поведения).
 */
final class ClientOrderStatusAggregator
{
    /**
     * @param  array<int, OrderStatus>  $statuses
     */
    public function aggregate(array $statuses): OrderStatus
    {
        if ($statuses === []) {
            return OrderStatus::Pending;
        }

        $allCancelled = true;
        foreach ($statuses as $status) {
            if ($status !== OrderStatus::Cancelled) {
                $allCancelled = false;
                break;
            }
        }
        if ($allCancelled) {
            return OrderStatus::Cancelled;
        }

        $progressOrder = [
            OrderStatus::Pending->value => 0,
            OrderStatus::Assembling->value => 1,
            OrderStatus::Assembled->value => 2,
            OrderStatus::InDelivery->value => 3,
            OrderStatus::Delivered->value => 4,
        ];

        $minProgress = null;
        $minStatus = null;
        foreach ($statuses as $status) {
            if ($status === OrderStatus::Cancelled) {
                continue;
            }
            $progress = $progressOrder[$status->value] ?? null;
            if ($progress === null) {
                continue;
            }
            if ($minProgress === null || $progress < $minProgress) {
                $minProgress = $progress;
                $minStatus = $status;
            }
        }

        return $minStatus ?? OrderStatus::Pending;
    }
}
