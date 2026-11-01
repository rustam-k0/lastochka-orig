<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderHistory;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;

/**
 * Публикация аналитических событий (LASTOCHKI-JAMI-11659).
 *
 * В admin_back публикуется только order_stage.changed — отражение каждой
 * записи order_history в шину (сквозной «путь заказа» для analytics_back).
 * Префикс order_stage (НЕ order.*): события order.* биндят inbound-очереди
 * других сервисов — order_stage.changed не должен туда попадать.
 *
 * Инвариант: аналитика НИКОГДА не роняет бизнес-операцию — метод обёрнут
 * в try/catch с warning-логом.
 */
final class AnalyticsEvents
{
    private const ENTITY_ORDER_STAGES = 'order_stages';

    /**
     * Вызывается из OrderHistory::log() после create.
     */
    public static function orderStageChanged(OrderHistory $history): void
    {
        try {
            $order = Order::withTrashed()->find($history->order_id);

            if (!$order) {
                return;
            }

            $externalClientId = $order->client_id
                ? EventSyncMetadata::where('entity_type', 'customers')
                    ->where('entity_id', $order->client_id)
                    ->value('external_id')
                : null;

            $shopCode = $order->shop?->code;

            CustomEventPublisher::dispatch(
                self::ENTITY_ORDER_STAGES,
                $history,
                'changed',
                [
                    'session_id' => $history->actor_type === OrderHistory::ACTOR_STAFF
                        ? self::employeeSessionId($history->actor_id)
                        : null,
                    'order_id' => (string) $order->uuid,
                    'order_local_id' => $order->id,
                    'correlation_id' => (string) $order->uuid,
                    'client_id' => $externalClientId ?? ($order->client_id !== null ? (string) $order->client_id : null),
                    'client_id_scope' => $externalClientId !== null ? 'storefront' : 'local',
                    'occurred_at' => now()->toIso8601String(),
                    'action' => $history->action,
                    'actor_type' => $history->actor_type,
                    'actor_id' => $history->actor_id,
                    'actor_name' => $history->actor_name,
                    'old_value' => $history->old_value,
                    'new_value' => $history->new_value,
                    'comment' => $history->comment,
                ],
                is_string($shopCode) && $shopCode !== '' ? $shopCode : null,
            );
        } catch (\Throwable $e) {
            \Log::warning('AnalyticsEvents publish failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * session_id рабочей сессии сотрудника = его открытая смена
     * (shifts в общей docker-БД; модели Shift в этом сервисе нет).
     */
    private static function employeeSessionId(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $shiftId = \DB::table('shifts')
            ->where('user_id', $userId)
            ->whereNull('finished_at')
            ->whereNull('deleted_at')
            ->value('id');

        return $shiftId !== null ? 'shift-' . $shiftId : null;
    }
}
