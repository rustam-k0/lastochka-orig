<?php

namespace App\Services;

use App\Models\Assembly;
use App\Models\AssemblyItem;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCancelReason;
use App\Models\ProductReplaceReason;
use App\Models\Status;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\CustomEventPublisher;

/**
 * Публикация аналитических событий P0 (LASTOCHKI-JAMI-11659).
 *
 * Каждое событие уходит через CustomEventPublisher (transactional outbox) и
 * потребляется lastochka_analytics_back в журнал analytics_events.
 *
 * Инвариант: аналитика НИКОГДА не роняет бизнес-мутацию — все методы
 * обёрнуты в try/catch с warning-логом.
 *
 * Routing keys: picking.started / picking.item_assembled / picking.item_replaced /
 * picking.item_not_found / picking.finished / delivery.courier_assigned /
 * delivery.started / delivery.arrived / delivery.finished / order_stage.changed.
 * Префикс order_stage (не order.*) — иначе событие съедят существующие
 * inbound-очереди сервисов, подписанные на order.*.
 */
final class AnalyticsEvents
{
    private const ENTITY_PICKING = 'picking';
    private const ENTITY_PICKING_ITEMS = 'picking_items';
    private const ENTITY_DELIVERY = 'delivery_stages';
    private const ENTITY_ORDER_STAGES = 'order_stages';
    private const ENTITY_SHIFTS = 'shifts';

    /**
     * Смена открыта (UserConfirmSelfie) — здесь рождается session_id
     * рабочей сессии сотрудника (#11599, B2E).
     */
    public static function shiftStarted(\App\Models\Shift $shift): void
    {
        self::safely(function () use ($shift) {
            CustomEventPublisher::dispatch(
                self::ENTITY_SHIFTS,
                $shift,
                'started',
                array_merge(self::shiftContext($shift), [
                    'occurred_at' => now()->toIso8601String(),
                ]),
                $shift->shop?->code,
            );
        });
    }

    /**
     * Смена закрыта (finishShifts / будущее явное закрытие).
     */
    public static function shiftEnded(\App\Models\Shift $shift): void
    {
        self::safely(function () use ($shift) {
            CustomEventPublisher::dispatch(
                self::ENTITY_SHIFTS,
                $shift,
                'ended',
                array_merge(self::shiftContext($shift), [
                    'occurred_at' => now()->toIso8601String(),
                    'started_at' => self::iso($shift->created_at),
                    'shift_seconds' => $shift->created_at ? now()->diffInSeconds($shift->created_at) : null,
                ]),
                $shift->shop?->code,
            );
        });
    }

    /**
     * Контекст смены: сотрудник, роль (collector → picker), session_id.
     */
    private static function shiftContext(\App\Models\Shift $shift): array
    {
        $user = \App\Models\User::withoutGlobalScopes()->find($shift->user_id);
        $roleName = $user?->getRoleNames()->first();

        return [
            'session_id' => 'shift-' . $shift->id,
            'shift_id' => $shift->id,
            'employee_id' => $shift->user_id,
            'role' => $roleName === 'collector' ? 'picker' : $roleName,
        ];
    }

    /**
     * session_id рабочей сессии исполнителя = его открытая смена.
     * Мутации сборки/доставки гейтятся onShift, так что смена обычно есть;
     * NotFinished global scope на Shift сам отфильтрует закрытые.
     */
    private static function employeeSessionId(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $shiftId = \App\Models\Shift::where('user_id', $userId)->value('id');

        return $shiftId !== null ? 'shift-' . $shiftId : null;
    }

    public static function pickingStarted(Assembly $assembly): void
    {
        self::safely(function () use ($assembly) {
            $order = $assembly->order ?? Order::find($assembly->order_id);

            if (!$order) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING,
                $assembly,
                'started',
                array_merge(self::orderContext($order), [
                    'picker_id' => $assembly->user_id,
                    'session_id' => self::employeeSessionId($assembly->user_id),
                    'assembly_id' => $assembly->id,
                    'items_count' => $assembly->assemblyItems()->count(),
                ]),
                self::shopCode($order),
            );
        });
    }

    /**
     * Старт готовки (кухня) — отдельная веха между взятием заказа и сборкой.
     * picking.started публикуется в OrderPrepare, здесь — picking.cooking_started.
     */
    public static function cookingStarted(Assembly $assembly): void
    {
        self::safely(function () use ($assembly) {
            $order = $assembly->order ?? Order::find($assembly->order_id);

            if (!$order) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING,
                $assembly,
                'cooking_started',
                array_merge(self::orderContext($order), [
                    'picker_id' => $assembly->user_id,
                    'session_id' => self::employeeSessionId($assembly->user_id),
                    'assembly_id' => $assembly->id,
                ]),
                self::shopCode($order),
            );
        });
    }

    public static function itemAssembled(AssemblyItem $item): void
    {
        self::safely(function () use ($item) {
            $order = $item->assembly?->order ?? $item->orderItem?->order;

            if (!$order) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING_ITEMS,
                $item,
                'item_assembled',
                array_merge(
                    self::orderContext($order),
                    self::itemContext($item),
                    self::productContext($item->orderItem?->product, 'product'),
                ),
                self::shopCode($order),
            );
        });
    }

    public static function itemReplaced(AssemblyItem $item, ?Product $original, ?Product $replacement): void
    {
        self::safely(function () use ($item, $original, $replacement) {
            $order = $item->assembly?->order ?? $item->orderItem?->order;

            if (!$order) {
                return;
            }

            $reason = $item->replace_reason_id
                ? ProductReplaceReason::find($item->replace_reason_id)?->title
                : null;

            $originalPrice = $item->orderItem?->price_during_order;
            // Сетевой каталог: цена замены пер-магазин, иначе в телеметрию
            // уехала бы цена произвольного магазина сети.
            $replacementPrice = $replacement?->effectivePriceForShop($order->shop_id)?->value;

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING_ITEMS,
                $item,
                'item_replaced',
                array_merge(
                    self::orderContext($order),
                    self::itemContext($item),
                    self::productContext($original, 'original_product'),
                    self::productContext($replacement, 'replacement_product'),
                    [
                        'replace_reason_id' => $item->replace_reason_id,
                        'replace_reason' => $reason,
                        'original_unit_price' => $originalPrice !== null ? (float) $originalPrice : null,
                        'replacement_unit_price' => $replacementPrice !== null ? (float) $replacementPrice : null,
                        'price_delta' => ($originalPrice !== null && $replacementPrice !== null)
                            ? round((float) $replacementPrice - (float) $originalPrice, 2)
                            : null,
                    ],
                ),
                self::shopCode($order),
            );
        });
    }

    public static function itemNotFound(AssemblyItem $item, ?int $cancelReasonId): void
    {
        self::safely(function () use ($item, $cancelReasonId) {
            $order = $item->assembly?->order ?? $item->orderItem?->order;

            if (!$order) {
                return;
            }

            $reason = $cancelReasonId
                ? ProductCancelReason::find($cancelReasonId)?->title
                : null;

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING_ITEMS,
                $item,
                'item_not_found',
                array_merge(
                    self::orderContext($order),
                    self::itemContext($item),
                    self::productContext($item->orderItem?->product, 'product'),
                    [
                        'cancel_reason_id' => $cancelReasonId,
                        'cancel_reason' => $reason,
                    ],
                ),
                self::shopCode($order),
            );
        });
    }

    /**
     * Скан товара при сборке (#11600): источник метрик «точность сборки»
     * и «% QR». method: qr|manual, result: ok|error.
     */
    public static function itemScanned(
        Assembly $assembly,
        ?int $productId,
        ?int $assemblyItemId,
        string $method,
        string $result,
        ?string $barcode = null
    ): void {
        self::safely(function () use ($assembly, $productId, $assemblyItemId, $method, $result, $barcode) {
            $order = $assembly->order ?? Order::withoutGlobalScopes()->find($assembly->order_id);

            if (!$order) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING,
                $assembly,
                'item_scanned',
                array_merge(
                    self::orderContext($order),
                    self::productContext($productId ? Product::find($productId) : null, 'product'),
                    [
                        'picker_id' => $assembly->user_id,
                        'session_id' => self::employeeSessionId($assembly->user_id),
                        'assembly_id' => $assembly->id,
                        'assembly_item_id' => $assemblyItemId,
                        'method' => $method,
                        'result' => $result,
                        'barcode' => $barcode,
                    ],
                ),
                self::shopCode($order),
            );
        });
    }

    public static function pickingFinished(Assembly $assembly): void
    {
        self::safely(function () use ($assembly) {
            $order = $assembly->order ?? Order::find($assembly->order_id);

            if (!$order) {
                return;
            }

            $items = $assembly->assemblyItems()->with('orderItem')->get();

            $assembled = $items->where('status_id', Status::PRODUCT_ASSEMBLED);
            $cancelled = $items->where('status_id', Status::PRODUCT_CANCELED);

            $orderedAmount = 0.0;
            foreach ($items as $assemblyItem) {
                $orderItem = $assemblyItem->orderItem;
                if (!$orderItem) {
                    continue;
                }

                // Заказанное клиентом: у замены — исходный товар по его цене,
                // иначе заказанные порции (quantity уже переписан фактом сборки).
                $orderedAmount += $orderItem->origin_product_id !== null && $orderItem->origin_quantity !== null
                    ? (float) ($orderItem->origin_price ?? $orderItem->price_during_order) * (int) $orderItem->origin_quantity
                    : (float) $orderItem->price_during_order * (int) ($orderItem->ordered_quantity ?? $orderItem->quantity);
            }

            $replacedCount = OrderItem::where('order_id', $order->id)
                ->whereNotNull('origin_product_id')
                ->count();

            CustomEventPublisher::dispatch(
                self::ENTITY_PICKING,
                $assembly,
                'finished',
                array_merge(self::orderContext($order), [
                    'picker_id' => $assembly->user_id,
                    'session_id' => self::employeeSessionId($assembly->user_id),
                    'assembly_id' => $assembly->id,
                    'ordered_items' => $items->count(),
                    'assembled_items' => $assembled->count(),
                    'cancelled_items' => $cancelled->count(),
                    'replaced_items' => $replacedCount,
                    'ordered_amount' => round($orderedAmount, 2),
                    'assembled_amount' => round((float) $assembled->sum('price'), 2),
                    'total_weight' => $order->total_weight !== null ? (int) $order->total_weight : null,
                    'is_weight_partial' => (bool) $order->is_weight_partial,
                    'cooking_started_at' => self::iso($assembly->cooking_started_at),
                ]),
                self::shopCode($order),
            );
        });
    }

    public static function courierAssigned(Delivery $delivery, Order $order): void
    {
        self::safely(function () use ($delivery, $order) {
            CustomEventPublisher::dispatch(
                self::ENTITY_DELIVERY,
                $delivery,
                'courier_assigned',
                array_merge(self::orderContext($order), [
                    'courier_id' => $delivery->user_id,
                    'session_id' => self::employeeSessionId($delivery->user_id),
                    'delivery_id' => $delivery->id,
                ]),
                self::shopCode($order),
            );
        });
    }

    public static function deliveryStarted(Delivery $delivery, Order $order): void
    {
        self::safely(function () use ($delivery, $order) {
            CustomEventPublisher::dispatch(
                self::ENTITY_DELIVERY,
                $delivery,
                'started',
                array_merge(self::orderContext($order), [
                    'courier_id' => $delivery->user_id,
                    'session_id' => self::employeeSessionId($delivery->user_id),
                    'delivery_id' => $delivery->id,
                ]),
                self::shopCode($order),
            );
        });
    }

    public static function deliveryArrived(DeliveryOrder $deliveryOrder): void
    {
        self::safely(function () use ($deliveryOrder) {
            $deliveryOrder->loadMissing(['order', 'delivery']);
            $order = $deliveryOrder->order;
            $delivery = $deliveryOrder->delivery;

            if (!$order || !$delivery) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_DELIVERY,
                $delivery,
                'arrived',
                array_merge(self::orderContext($order), [
                    'courier_id' => $delivery->user_id,
                    'session_id' => self::employeeSessionId($delivery->user_id),
                    'delivery_id' => $delivery->id,
                    'started_at' => self::iso($deliveryOrder->started_at),
                ]),
                self::shopCode($order),
            );
        });
    }

    public static function deliveryFinished(Delivery $delivery, Order $order, bool $allReturned): void
    {
        self::safely(function () use ($delivery, $order, $allReturned) {
            CustomEventPublisher::dispatch(
                self::ENTITY_DELIVERY,
                $delivery,
                'finished',
                array_merge(self::orderContext($order), [
                    'courier_id' => $delivery->user_id,
                    'session_id' => self::employeeSessionId($delivery->user_id),
                    'delivery_id' => $delivery->id,
                    'all_returned' => $allReturned,
                ]),
                self::shopCode($order),
            );
        });
    }

    /**
     * Агрегат пробега за доставку заказа (#11602, P2): приложение курьера
     * присылает суммарный GPS-трек постфактум — в т.ч. после завершения
     * доставки, поэтому связи грузим без global scope NotFinished.
     */
    public static function distanceLogged(DeliveryOrder $deliveryOrder, int $distanceM): void
    {
        self::safely(function () use ($deliveryOrder, $distanceM) {
            $order = $deliveryOrder->order ?? Order::withoutGlobalScopes()->find($deliveryOrder->order_id);
            $delivery = Delivery::withoutGlobalScopes()->find($deliveryOrder->delivery_id);

            if (!$order || !$delivery) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_DELIVERY,
                $delivery,
                'distance_logged',
                array_merge(self::orderContext($order), [
                    'courier_id' => $delivery->user_id,
                    'session_id' => self::employeeSessionId($delivery->user_id),
                    'delivery_id' => $delivery->id,
                    'distance_m' => $distanceM,
                ]),
                self::shopCode($order),
            );
        });
    }

    /**
     * Отражение записи order_history в шину — сквозной «путь заказа».
     * Вызывается из OrderHistory::log() после create.
     */
    public static function orderStageChanged(OrderHistory $history): void
    {
        self::safely(function () use ($history) {
            $order = Order::withTrashed()->find($history->order_id);

            if (!$order) {
                return;
            }

            CustomEventPublisher::dispatch(
                self::ENTITY_ORDER_STAGES,
                $history,
                'changed',
                array_merge(self::orderContext($order), [
                    'session_id' => $history->actor_type === OrderHistory::ACTOR_STAFF
                        ? self::employeeSessionId($history->actor_id)
                        : null,
                    'action' => $history->action,
                    'actor_type' => $history->actor_type,
                    'actor_id' => $history->actor_id,
                    'actor_name' => $history->actor_name,
                    'old_value' => $history->old_value,
                    'new_value' => $history->new_value,
                    'comment' => $history->comment,
                ]),
                self::shopCode($order),
            );
        });
    }

    /**
     * Общий контекст заказа: сквозной uuid, локальный id, клиент.
     * client_id — external id клиента (storefront user id) из event_sync_metadata;
     * fallback на локальный id docker-БД с пометкой client_id_scope.
     */
    private static function orderContext(Order $order): array
    {
        $externalClientId = $order->client_id
            ? EventSyncMetadata::where('entity_type', 'customers')
                ->where('entity_id', $order->client_id)
                ->value('external_id')
            : null;

        return [
            'order_id' => (string) $order->uuid,
            'order_local_id' => $order->id,
            'correlation_id' => (string) $order->uuid,
            'client_id' => $externalClientId ?? ($order->client_id !== null ? (string) $order->client_id : null),
            'client_id_scope' => $externalClientId !== null ? 'storefront' : 'local',
            // SLA и GMV для агрегатора B2E (#11595/#11596)
            'slot_start_date' => self::iso($order->slot_start_date),
            'slot_end_date' => self::iso($order->slot_end_date),
            'order_amount' => $order->amount !== null ? (float) $order->amount : null,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    private static function itemContext(AssemblyItem $item): array
    {
        return [
            'assembly_item_id' => $item->id,
            'order_item_id' => $item->order_item_id,
            'picker_id' => $item->assembly?->user_id,
            'session_id' => self::employeeSessionId($item->assembly?->user_id),
            'quantity' => $item->quantity,
            'actual_weight' => $item->actual_weight,
            'line_price' => $item->price !== null ? (float) $item->price : null,
            'assembled_at' => self::iso($item->assembled_at),
        ];
    }

    private static function productContext(?Product $product, string $prefix): array
    {
        if (!$product) {
            return ["{$prefix}_id" => null];
        }

        $externalId = EventSyncMetadata::where('entity_type', 'products')
            ->where('entity_id', $product->id)
            ->value('external_id');

        return [
            "{$prefix}_id" => $product->id,
            "{$prefix}_external_id" => $externalId,
            "{$prefix}_title" => $product->title,
        ];
    }

    private static function shopCode(Order $order): ?string
    {
        $code = $order->shop?->code;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Приводит значение таймстемпа к ISO8601. У моделей docker-БД datetime-поля
     * зачастую без casts — приходят строками.
     */
    private static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \Carbon\Carbon::instance($value)->toIso8601String();
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            \Log::warning('AnalyticsEvents publish failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
