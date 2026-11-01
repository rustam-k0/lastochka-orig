<?php

declare(strict_types=1);

namespace App\RabbitMQ\Transformers;

use App\Enums\OrderStatus;
use App\Enums\PromocodeType;
use App\Events\OrderStatusChangedEvent;
use App\Jobs\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Pricing\OrderItemPortions;
use App\Services\Pricing\DeliveryPriceSnapshot;
use App\Services\Pricing\PriceTierQuote;
use App\Services\Pricing\PriceTierSnapshot;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OrderEventTransformer extends BaseEventTransformer
{
    /**
     * Заказы, у которых beforeUpdate зафиксировал переход в «Собран»: уведомление
     * отправит afterUpdate после capture. Ключ — id заказа; консьюмер вызывает
     * оба хука на одном экземпляре трансформера подряд для одного события.
     *
     * @var array<int, true>
     */
    private array $deferredAssembledNotifications = [];

    /**
     * @return array<string>
     */
    protected function requiredFields(): array
    {
        return ['event_id', 'id', 'source'];
    }

    /**
     * Преобразовать заказ в событие order.created.
     *
     * @return array<string, mixed>
     */
    public function toEvent(Model $order, ?object $shop = null, ?string $eventId = null): array
    {
        /** @var Order $order */
        $order->load(['user', 'store', 'items.supplements.supplement', 'items.supplements.values', 'address', 'promocode']);

        $eventData = [
            'event_id' => $eventId ?? Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => $order->updated_at->toIso8601String(),
            'event_source' => config('rabbitmq-events.source'),
            'id' => (string) $order->id,
            'shop_code' => $order->shopCodeForEvents(),
            'source' => config('rabbitmq-events.source'),
            'client_id' => (string) $order->user_id,
            'client_source' => config('rabbitmq-events.source'),
            'client_info' => $this->buildClientInfo($order),
            'items' => $this->buildItems($order),
            'status' => $this->mapStatusToRabbit($order->status),
            'items_subtotal' => $this->toRubles($order->subtotal),
            'discount_total' => $this->toRubles($order->discount),
            'eligible_amount' => $this->toRubles($order->eligible_amount ?? 0),
            'items_total_after_discount' => $this->toRubles($order->subtotal - $order->discount),
            'shipping_total' => $this->toRubles($order->delivery_cost),
            // Стоимость сборки (модуль assembly_cost) — входит в grand_total; отдельным
            // полем нужна delivery_back, чтобы пересчёты суммы после сборки не теряли её
            // (иначе capture в YooKassa < hold — недосбор у мерчанта).
            'assembly_cost' => $this->toRubles($order->assembly_cost ?? 0),
            'grand_total' => $this->toRubles($order->total),
            'bonus_spent' => $order->bonus_spent ?? 0,
            'bonus_earned' => $order->bonus_earned ?? 0,
            // Карта лояльности клиента (LASTOCHKI-JAMI-12013): считается только
            // подтверждённая 1С карта — pending на офлайн-кассе не работает.
            'has_card' => (bool) $order->user?->hasActiveCard(),
            'card_number' => $order->user?->hasActiveCard() ? $order->user->card_number : null,
            'is_paid' => $order->is_paid,
            // Тяжёлый заказ: чекаут-оценка веса (граммы, по корневым позициям) — чтобы
            // сборщик видел вес и плашку ДО завершения сборки (delivery_back пересчитает
            // фактический вес в AssemblyFinish).
            'is_heavy' => (bool) $order->is_heavy,
            'total_weight' => \App\Support\WeightAggregator::sumGrams($order->items->whereNull('parent_id')),
            'is_weight_partial' => $order->items->whereNull('parent_id')->contains(fn ($item) => $item->grams() === null),
            'payment_type' => $order->payment_type?->value,
            // MID-285: момент холдирования средств. В режиме «оформление без
            // слотов» от него курьерка строит окно доставки для сборщика и
            // курьера; null — холда не было (наличные) либо он ещё не подтверждён.
            'payment_held_at' => $order->payment_held_at?->toIso8601String(),
            'slot_start_date' => $order->delivery_slot_start?->toIso8601String(),
            'slot_end_date' => $order->delivery_slot_end?->toIso8601String(),
            'slot_time_slot' => $order->delivery_slot_display,
            'slot_date' => $order->delivery_slot_start?->format('Y-m-d'),
            'shipping_method' => $order->shipping_method?->value,
            'address' => $this->buildAddress($order),
            'is_b2b' => (bool) $order->store?->is_b2b,
            'promocode' => $this->buildPromocode($order),
            'client_comment' => $order->comment,
            // Опции чекаута (MENDEV-17): сборщик должен видеть «Охладить
            // перед доставкой» и другие выбранные опции. Снапшот [{code, label}]
            'checkout_options' => $order->checkout_options ?? [],
            'created_at' => $order->created_at->toIso8601String(),
            // Аналитика (LASTOCHKI-JAMI-11659, контракт #11642): сессия
            // приложения и анонимный id устройства, связка поведения с заказом.
            'session_id' => $order->analytics_session_id,
            'device_client_id' => $order->analytics_device_client_id,
            'order_id' => (string) $order->id,
            'correlation_id' => (string) $order->id,
        ];

        // delivery_price_snapshot (MID-280): снимок условий доставки, по нему курьерка
        // пересчитывает доставку после сборки; у заказов до выкладки ключа нет.
        foreach (['shipping_tariff_code', 'pickup_external_id', 'pickup_address_data', 'cdek_number', 'cdek_status', 'rp_barcode', 'rp_status', 'delivery_price_snapshot'] as $f) {
            if ($order->{$f} !== null) {
                $eventData[$f] = $order->{$f};
            }
        }

        $yandexClaim = $order->yandexClaim()->first();
        if ($yandexClaim) {
            $eventData['yandex_claim_id'] = $yandexClaim->claim_id;
            $eventData['yandex_delivery_status'] = $yandexClaim->status->value;
            $eventData['yandex_courier_name'] = $yandexClaim->courier_name;
            $eventData['yandex_courier_phone'] = $yandexClaim->courier_phone;
            $eventData['yandex_delivery_price'] = $yandexClaim->price;
        }

        return $eventData;
    }

    /**
     * Кастомный payload для событий order_created, order_paid, order_cancelled.
     *
     * @param  array<string, mixed>  $customPayload
     * @return array<string, mixed>|null
     */
    public function toCustomEvent(
        Model $order,
        string $eventType,
        array $customPayload,
        ?string $eventId = null
    ): ?array {
        /** @var Order $order */
        if ($eventType === 'order_created') {
            // Полный payload для создания заказа
            $order->load(['user', 'store', 'items.supplements.supplement', 'items.supplements.values', 'address', 'promocode']);

            $eventData = [
                'event_type' => 'created',
                'client_id' => (string) $order->user_id,
                'client_source' => config('rabbitmq-events.source'),
                'client_info' => $this->buildClientInfo($order),
                'items' => $this->buildItems($order),
                'status' => $this->mapStatusToRabbit($order->status),
                'items_subtotal' => $this->toRubles($order->subtotal),
                'discount_total' => $this->toRubles($order->discount),
                'eligible_amount' => $this->toRubles($order->eligible_amount ?? 0),
                'items_total_after_discount' => $this->toRubles($order->subtotal - $order->discount),
                'shipping_total' => $this->toRubles($order->delivery_cost),
                // Стоимость сборки (модуль assembly_cost) — см. комментарий в toEvent()
                'assembly_cost' => $this->toRubles($order->assembly_cost ?? 0),
                'grand_total' => $this->toRubles($order->total),
                'bonus_spent' => $order->bonus_spent ?? 0,
                'bonus_earned' => $order->bonus_earned ?? 0,
                // Карта лояльности клиента (LASTOCHKI-JAMI-12013): считается только
                // подтверждённая 1С карта — pending на офлайн-кассе не работает.
                'has_card' => (bool) $order->user?->hasActiveCard(),
                'card_number' => $order->user?->hasActiveCard() ? $order->user->card_number : null,
                'is_paid' => $order->is_paid,
                'payment_type' => $order->payment_type?->value,
                // MID-285: см. комментарий в toEvent()
                'payment_held_at' => $order->payment_held_at?->toIso8601String(),
                'slot_start_date' => $order->delivery_slot_start?->toIso8601String(),
                'slot_end_date' => $order->delivery_slot_end?->toIso8601String(),
                'slot_time_slot' => $order->delivery_slot_display,
                'slot_date' => $order->delivery_slot_start?->format('Y-m-d'),
                'shipping_method' => $order->shipping_method?->value,
                'address' => $this->buildAddress($order),
                'is_b2b' => (bool) $order->store?->is_b2b,
                'promocode' => $this->buildPromocode($order),
                'client_comment' => $order->comment,
                'checkout_options' => $order->checkout_options ?? [],
                'created_at' => $order->created_at->toIso8601String(),
            ];

            // delivery_price_snapshot (MID-280): снимок условий доставки, по нему курьерка
            // пересчитывает доставку после сборки; у заказов до выкладки ключа нет.
            foreach (['shipping_tariff_code', 'pickup_external_id', 'pickup_address_data', 'cdek_number', 'cdek_status', 'rp_barcode', 'rp_status', 'delivery_price_snapshot'] as $f) {
                if ($order->{$f} !== null) {
                    $eventData[$f] = $order->{$f};
                }
            }

            $yandexClaim = $order->yandexClaim()->first();
            if ($yandexClaim) {
                $eventData['yandex_claim_id'] = $yandexClaim->claim_id;
                $eventData['yandex_delivery_status'] = $yandexClaim->status->value;
                $eventData['yandex_courier_name'] = $yandexClaim->courier_name;
                $eventData['yandex_courier_phone'] = $yandexClaim->courier_phone;
                $eventData['yandex_delivery_price'] = $yandexClaim->price;
            }

            return $eventData;
        }

        if ($eventType === 'order_paid') {
            $order->load('store');

            return [
                'event_type' => 'paid',
                'is_paid' => true,
                'payment_type' => $order->payment_type?->value,
                'payment_status' => 'paid',
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        // MID-285: момент холдирования средств по заказу, который уже уехал
        // в курьерку. Полезная нагрузка минимальна — событие только доносит
        // метку, платёжный статус холда отдельным событием не менялся и раньше.
        if ($eventType === 'order_payment_held') {
            $order->load('store');

            return [
                'event_type' => 'payment_held',
                'payment_held_at' => $order->payment_held_at?->toIso8601String(),
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        if ($eventType === 'order_payment_failed') {
            $order->load('store');

            return [
                'event_type' => 'payment_failed',
                'payment_status' => 'failed',
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        if ($eventType === 'order_surcharge_required') {
            $order->load('store');

            return [
                'event_type' => 'surcharge_required',
                'payment_status' => 'awaiting_surcharge',
                'surcharge_amount' => $this->toRubles($order->surcharge_amount ?? 0),
                'surcharge_expires_at' => $order->surcharge_expires_at?->toIso8601String(),
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        if ($eventType === 'order_refunded') {
            $order->load('store');

            return [
                'event_type' => 'refunded',
                'payment_status' => 'refunded',
                'refunded_amount' => $this->toRubles($order->refunded_amount ?? 0),
                'updated_at' => $order->updated_at->toIso8601String(),
            ];
        }

        if ($eventType === 'order_cancelled') {
            $order->load('store');

            $eventData = [
                'event_type' => 'cancelled',
                'status' => 'cancelled',
                'updated_at' => $order->updated_at->toIso8601String(),
            ];

            $yandexClaim = $order->yandexClaim()->first();
            if ($yandexClaim) {
                $eventData['yandex_claim_id'] = $yandexClaim->claim_id;
                $eventData['yandex_delivery_status'] = $yandexClaim->status->value;
                $eventData['yandex_courier_name'] = $yandexClaim->courier_name;
                $eventData['yandex_courier_phone'] = $yandexClaim->courier_phone;
                $eventData['yandex_delivery_price'] = $yandexClaim->price;
            }

            return $eventData;
        }

        if ($eventType === 'order_supplemented') {
            $order->load(['user', 'store', 'items.supplements.supplement', 'items.supplements.values']);

            $eventData = [
                'event_type' => 'supplemented',
                'items' => $this->buildItems($order),
                'items_subtotal' => $this->toRubles($order->subtotal),
                'discount_total' => $this->toRubles($order->discount),
                'items_total_after_discount' => $this->toRubles($order->subtotal - $order->discount),
                'shipping_total' => $this->toRubles($order->delivery_cost),
                'grand_total' => $this->toRubles($order->total),
                'bonus_spent' => $order->bonus_spent ?? 0,
                'bonus_earned' => $order->bonus_earned ?? 0,
                // Карта лояльности клиента (LASTOCHKI-JAMI-12013): считается только
                // подтверждённая 1С карта — pending на офлайн-кассе не работает.
                'has_card' => (bool) $order->user?->hasActiveCard(),
                'card_number' => $order->user?->hasActiveCard() ? $order->user->card_number : null,
                'updated_at' => $order->updated_at->toIso8601String(),
            ];

            $yandexClaim = $order->yandexClaim()->first();
            if ($yandexClaim) {
                $eventData['yandex_claim_id'] = $yandexClaim->claim_id;
                $eventData['yandex_delivery_status'] = $yandexClaim->status->value;
                $eventData['yandex_courier_name'] = $yandexClaim->courier_name;
                $eventData['yandex_courier_phone'] = $yandexClaim->courier_phone;
                $eventData['yandex_delivery_price'] = $yandexClaim->price;
            }

            return $eventData;
        }

        if ($eventType === 'order_yandex_updated') {
            // Снимок условий доставки едет только в событии создания (MID-280): обновление
            // статуса Яндекса не должно затирать снимок, пересобранный курьеркой.
            $eventData = $this->toEvent($order);
            unset($eventData['delivery_price_snapshot']);

            return $eventData;
        }

        return null;
    }

    /**
     * Поиск существующего заказа по ID.
     *
     * Когда событие приходит от 1С (source=1c), поле id содержит
     * shop_order_id из 1С-парсера, который равен ID заказа в сторфронте.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     */
    public function findExistingEntity(array $eventData, array $transformedData): ?Model
    {
        $id = $eventData['id'] ?? null;

        if ($id === null) {
            return null;
        }

        return Order::find((int) $id);
    }

    /**
     * Преобразовать входящее событие в данные модели Order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromEvent(array $data): array
    {
        $attributes = [
            'status' => $this->mapStatusFromRabbit($data['status'] ?? 'created'),
            'tracking_number' => $data['tracking_number'] ?? null,
        ];

        // Conditional set: поля доставки СДЭК/Почта России — применяем только если пришли в payload.
        // Используем array_key_exists (не isset) чтобы корректно обрабатывать явный null.
        $deliveryFields = [
            'shipping_method',
            'shipping_tariff_code',
            'pickup_external_id',
            'pickup_address_data',
            'cdek_number',
            'cdek_status',
            'rp_barcode',
            'rp_status',
        ];

        foreach ($deliveryFields as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        // Тяжёлый заказ / совместная доставка: признак и число курьеров приходят
        // в order.updated от платформы (admin_back / delivery_back). Отсутствие
        // ключей в старых payload — поля не трогаем.
        if (array_key_exists('is_heavy', $data)) {
            $attributes['is_heavy'] = (bool) $data['is_heavy'];
        }
        if (array_key_exists('couriers_count', $data)) {
            $attributes['couriers_count'] = (int) $data['couriers_count'];
        }

        return $attributes;
    }

    /**
     * Обработка входящего события order.status.changed от CRM.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function processStatusChangedEvent(Model $order, array $eventData): void
    {
        /** @var Order $order */
        $previousStatus = $order->status;
        $newStatus = $this->mapStatusFromRabbit($eventData['status']);

        // Проверяем, действительно ли статус изменился
        if ($previousStatus === $newStatus) {
            Log::info('Order status not changed, skipping notification', [
                'order_id' => $order->id,
                'status' => $newStatus->value,
            ]);

            return;
        }

        $update = [
            'status' => $newStatus,
            'tracking_number' => $eventData['tracking_number'] ?? $order->tracking_number,
        ];

        // Момент доставки (сортировка «Из истории заказов»); уже заполненный не трогаем.
        if ($newStatus === OrderStatus::Delivered && $order->delivered_at === null) {
            $update['delivered_at'] = now();
        }

        $order->update($update);

        // Отправляем событие о смене статуса для пуш-уведомлений
        event(new OrderStatusChangedEvent($order->fresh(), $previousStatus, $newStatus));

        Log::info('Order status changed, dispatching notification job', [
            'order_id' => $order->id,
            'old_status' => $previousStatus->value,
            'new_status' => $newStatus->value,
            'user_id' => $order->user_id,
        ]);

        SendOrderStatusNotification::dispatch($order->id, $newStatus);
    }

    /**
     * Хук: перед обновлением заказа — определяем смену статуса и отправляем push.
     *
     * @param  array<string, mixed>  $eventData
     * @param  array<string, mixed>  $transformedData
     * @return array<string, mixed>|null
     */
    public function beforeUpdate(Model $order, array $eventData, array $transformedData): ?array
    {
        /** @var Order $order */

        // Terminal-guard: если локально заказ уже в terminal статусе (Cancelled
        // или Delivered), не позволяем входящему order.updated событию перетереть
        // его другим статусом. Кейс LASTOCHKI-JAMI-9665: клиент отменяет заказ,
        // но из-за race condition сборщик в delivery_back успевает запустить
        // OrderPrepare → летит order.updated со status=assembling и перетирает
        // локальный Cancelled на Assembling.
        $incomingStatus = $transformedData['status'] ?? null;
        $isTerminal = in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Delivered], true);

        if ($isTerminal && $incomingStatus instanceof OrderStatus && $incomingStatus !== $order->status) {
            Log::warning('OrderEventTransformer::beforeUpdate — blocked status overwrite on terminal order', [
                'order_id' => $order->id,
                'local_status' => $order->status->value,
                'incoming_status' => $incomingStatus->value,
                'event_id' => $eventData['event_id'] ?? null,
            ]);

            unset($transformedData['status']);
        }

        // Shipping-setup поля (формируются при создании заказа) — не перезаписываем
        // для terminal-статусов. Трек-номера (cdek_number/status, rp_barcode/status) обновляем всегда —
        // они могут приходить уже после финализации заказа.
        if ($isTerminal) {
            foreach (['shipping_method', 'shipping_tariff_code', 'pickup_external_id', 'pickup_address_data'] as $setupField) {
                if (array_key_exists($setupField, $transformedData)) {
                    unset($transformedData[$setupField]);
                }
            }
        }

        $newStatus = $transformedData['status'] ?? null;
        $statusChanging = $newStatus instanceof OrderStatus && $order->status !== $newStatus;

        // Момент доставки (сортировка «Из истории заказов»); уже заполненный не трогаем.
        if ($statusChanging && $newStatus === OrderStatus::Delivered && $order->delivered_at === null) {
            $transformedData['delivered_at'] = now();
        }

        // Уведомление «Собран» шлём ПОСЛЕ capture: текст сравнивает списанное
        // с заблокированным (SendOrderStatusNotification::getAmountChangeText),
        // а очередь асинхронная — отправленный раньше job мог прочитать заказ
        // до списания. Capture теперь в afterUpdate (после синхронизации позиций),
        // поэтому «Собран» откладываем туда же. afterCommit — на случай внешней
        // транзакции консьюмера.
        unset($this->deferredAssembledNotifications[$order->id]);
        if ($statusChanging) {
            Log::info('Order status changing, dispatching notification', [
                'order_id' => $order->id,
                'old_status' => $order->status->value,
                'new_status' => $newStatus->value,
                'user_id' => $order->user_id,
            ]);

            if ($newStatus === OrderStatus::Assembled) {
                $this->deferredAssembledNotifications[$order->id] = true;
            } else {
                SendOrderStatusNotification::dispatch($order->id, $newStatus)->afterCommit();
            }
        }

        // Таймер авто-отмены для pickup при assembled
        if ($newStatus === OrderStatus::Assembled
            && $order->shipping_method === \App\Enums\ShippingMethod::Pickup
            && $order->status !== $newStatus
        ) {
            $this->handlePickupAssembled($order);
        }

        // Отмена Яндекс claim при отмене заказа через CRM/курьерку
        if ($newStatus === OrderStatus::Cancelled) {
            $yandexClaim = $order->yandexClaim;
            if ($yandexClaim && ! $yandexClaim->status->isTerminal()) {
                try {
                    app(\App\Services\YandexDelivery\YandexClaimService::class)->cancelClaim($yandexClaim);
                    Log::info('Yandex claim cancelled via status_changed event', [
                        'order_id' => $order->id,
                        'claim_id' => $yandexClaim->claim_id,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to cancel Yandex claim on status change', [
                        'order_id' => $order->id,
                        'claim_id' => $yandexClaim->claim_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Возврат денег при отмене заказа через CRM/курьерку: списанные (captured) —
        // рефандом, захолдированные — снятием холда. В marketplace-режиме sub-order
        // пропускаем — consolidated refund сделает FinalizeClientOrderAction (M.10=b).
        if ($newStatus === OrderStatus::Cancelled
            && $order->payment_type === \App\Enums\PaymentType::Card
        ) {
            $moduleConfig = app(\App\Services\Settings\ModuleConfigService::class);
            $isMarketplaceSubOrder = $moduleConfig->isMarketplaceMode() && $order->client_order_id !== null;

            if (! $isMarketplaceSubOrder) {
                try {
                    app(\App\Services\Payment\PaymentService::class)->refundOrReleaseHold($order);

                    Log::info('Payment refunded/released on order cancellation', [
                        'order_id' => $order->id,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Payment refund/hold-release failed on order cancellation', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $transformedData;
    }

    /**
     * Хук: после обновления заказа из входящего события.
     *
     * Синхронизирует позиции заказа (quantity, price, total)
     * и пересчитывает итоговые суммы заказа.
     *
     * @param  array<string, mixed>  $eventData
     */
    public function afterUpdate(Model $order, array $eventData): void
    {
        /** @var Order $order */

        // Отмена Яндекс claim при отмене заказа из курьерки
        if ($order->status === OrderStatus::Cancelled) {
            $yandexClaim = $order->yandexClaim;
            if ($yandexClaim && ! $yandexClaim->status->isTerminal()) {
                try {
                    app(\App\Services\YandexDelivery\YandexClaimService::class)->cancelClaim($yandexClaim);
                    Log::info('Yandex claim cancelled via order cancellation from admin', [
                        'order_id' => $order->id,
                        'claim_id' => $yandexClaim->claim_id,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Failed to cancel Yandex claim on order cancellation', [
                        'order_id' => $order->id,
                        'claim_id' => $yandexClaim->claim_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $newStatus = $this->mapStatusFromRabbit($eventData['status'] ?? 'created');

        // Sync items → bonus recalculate → (delivered earn | cancel refund).
        // Порядок критичен: начисление earned и refund spent должны считаться от
        // актуальных значений orders.bonus_* после пересчёта по новым items.

        if (! empty($eventData['items'])) {
            $this->syncItemsAndRecalc($order, $eventData);
        } else {
            // Items не пришли, но статус мог смениться на delivered/cancelled —
            // recalc всё равно корректен (idempotent no-op если состояние не изменилось).
            // Mirror-only: списание происходит в applyOnAssembled, не здесь.
            app(\App\Services\Bonus\BonusService::class)->recalculateMirror($order->fresh());
        }

        $order = $order->fresh();

        // Списание по факту сборки — только когда позиции уже синхронизированы:
        // capturePayment сразу ставит чек прихода. Статус проверяем и у события,
        // и у сохранённого заказа: terminal-guard beforeUpdate мог не пустить
        // assembled на отменённый/доставленный заказ.
        if ($newStatus === OrderStatus::Assembled && $order->status === OrderStatus::Assembled) {
            $this->captureOnAssembled($order, $eventData);
            $order = $order->fresh();
        }

        if (isset($this->deferredAssembledNotifications[$order->id])) {
            unset($this->deferredAssembledNotifications[$order->id]);
            SendOrderStatusNotification::dispatch($order->id, OrderStatus::Assembled)->afterCommit();
        }

        // Начисление бонусов при переходе в статус assembled (earn + spend).
        // В marketplace-режиме sub-order пропускаем — агрегация через handleDeliveredSubOrder.
        if ($newStatus === OrderStatus::Assembled) {
            $moduleConfig = app(\App\Services\Settings\ModuleConfigService::class);
            if (! ($moduleConfig->isMarketplaceMode() && $order->client_order_id !== null)) {
                app(\App\Services\Bonus\BonusService::class)->applyOnAssembled($order);
            }
        }

        // Safety-net при delivered: если событие assembled не пришло — применяем idempotently.
        // Router под marketplace feature flag (M.14=a, P2.4):
        //  - marketplace + sub-order (client_order_id !== null) → handleDeliveredSubOrder()
        //    (агрегация по client_order, одна earn транзакция когда все sub-orders delivered)
        //  - иначе → applyOnAssembled() (идемпотентно)
        if ($newStatus === OrderStatus::Delivered) {
            $moduleConfig = app(\App\Services\Settings\ModuleConfigService::class);

            if ($moduleConfig->isMarketplaceMode() && $order->client_order_id !== null) {
                $this->handleDeliveredSubOrder($order);
            } else {
                // safety-net: если событие assembled не пришло — применяем по факту delivered (идемпотентно)
                app(\App\Services\Bonus\BonusService::class)->applyOnAssembled($order);
            }

            // Фискализация наличных: факт доставки cash-заказа = факт оплаты.
            // НЕ гейтится на фискальный драйвер (в отличие от online/card в Task 8) —
            // наличные фискализируются при любом активном драйвере: ATOL бьёт обычную
            // продажу, YooKassa — независимый чек. Идемпотентно через гейт is_paid.
            // Rollout safety (GAP-2): весь блок гейтится на наличие АКТИВНОЙ фискальной
            // интеграции. Без неё контейнер фискализатора падает на YooKassaFiscalizer
            // (createIndependentReceipt) → реальный POST /receipts на КАЖДУЮ cash-доставку
            // на магазинах, которые фискализацию вообще не подключали.
            if (\App\Services\IntegrationConfigService::get('fiscal') !== null
                && $order->payment_type === \App\Enums\PaymentType::Cash
                && ! $order->is_paid) {
                $deliveredAmount = (float) ($eventData['grand_total'] ?? $order->total);
                if ($deliveredAmount > 0) {
                    $order->forceFill(['is_paid' => true])->saveQuietly(); // без outbound-события
                    \App\Jobs\FiscalizeSaleJob::dispatch(
                        $order->id,
                        \App\Enums\PaymentType::Cash->value,
                        $deliveredAmount,
                    );
                }
            }
        }

        // Частичный рефанд при доставке с возвратом части позиций курьером (tg-14265):
        // заказ уже captured на этапе Assembled, доставлен на меньшую сумму
        // (grand_total из delivery — авторитетная сумма доставленного) → вернуть
        // разницу. Полная отмена (Cancelled) обрабатывается в beforeUpdate отдельно.
        if ($newStatus === OrderStatus::Delivered
            && $order->payment_type === \App\Enums\PaymentType::Card
            && (float) ($order->captured_amount ?? 0) > 0
        ) {
            $moduleConfig = app(\App\Services\Settings\ModuleConfigService::class);
            $isMarketplaceSubOrder = $moduleConfig->isMarketplaceMode() && $order->client_order_id !== null;

            if (! $isMarketplaceSubOrder) {
                $delivered = (float) ($eventData['grand_total'] ?? $order->total);
                $refundable = round((float) $order->captured_amount - (float) ($order->refunded_amount ?? 0) - $delivered, 2);
                if ($refundable > 0.01) {
                    try {
                        app(\App\Services\Payment\PaymentService::class)->refundPayment($order, $refundable);
                        \Illuminate\Support\Facades\Log::info('Partial refund on delivery returns', [
                            'order_id' => $order->id,
                            'delivered' => $delivered,
                            'refunded' => $refundable,
                        ]);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Partial delivery refund failed', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Возврат бонусов при отмене заказа (внешняя отмена от delivery/admin).
        // В marketplace режиме внешняя отмена sub-order'а bonus transactions не трогает —
        // агрегированный refund произойдёт когда все sub-orders достигнут terminal статуса.
        if ($newStatus === OrderStatus::Cancelled) {
            $moduleConfig = app(\App\Services\Settings\ModuleConfigService::class);

            if (! ($moduleConfig->isMarketplaceMode() && $order->client_order_id !== null)) {
                app(\App\Services\Bonus\BonusService::class)->refundAll($order);
            }
        }
    }

    /**
     * Capture при завершении сборки (событие order.updated=assembled).
     *
     * Вызывается из afterUpdate ПОСЛЕ syncItemsAndRecalc: capturePayment ставит чек
     * прихода (FiscalizeSaleJob, а у ЮKassa чек уходит прямо в запросе capture), и
     * к этому моменту позиции заказа обязаны быть уже по факту сборки. Раньше блок
     * стоял в beforeUpdate, воркер очереди строил чек по заказанным количествам —
     * прод-заказ 1064: «Филе 1,5 кг» в чеке вместо привезённых 750 г.
     *
     * При модуле collector_payment списание выполняется синхронно мутацией
     * сборщика (settleAssemblyPayment) — событийный capture отключён, иначе
     * двойное списание / гонка с синхронным списанием.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function captureOnAssembled(Order $order, array $eventData): void
    {
        if ($order->payment_type === \App\Enums\PaymentType::Card
            && $order->held_amount > 0
            && ! $order->captured_amount
            && ! app(\App\Services\Settings\ModuleConfigService::class)->isCollectorPaymentEnabled()
        ) {
            try {
                $grandTotal = $eventData['grand_total'] ?? (float) $order->total;
                $heldAmount = (float) $order->held_amount;
                $paymentService = app(\App\Services\Payment\PaymentService::class);

                $shortfall = round($grandTotal - $heldAmount, 2);

                if ($grandTotal <= $heldAmount) {
                    // Сумма сборки <= холда — capture на фактическую сумму (разница разблокируется)
                    $paymentService->capturePayment($order, $grandTotal);
                } elseif ($shortfall < \App\Services\Payment\PaymentService::MIN_GATEWAY_CHARGE) {
                    // Недобор меньше минимального платежа шлюза (пересчёт весовых
                    // позиций по фактическому весу даёт копейки). ЮKassa такой платёж
                    // не проведёт — запрашивать его бессмысленно: капчурим холд целиком
                    // и не поднимаем «Ошибка оплаты» из-за копеек.
                    Log::info('Sub-minimum shortfall forgiven on assembled capture (legacy flow)', [
                        'order_id' => $order->id,
                        'grand_total' => $grandTotal,
                        'held_amount' => $heldAmount,
                        'shortfall' => $shortfall,
                    ]);

                    $paymentService->capturePayment($order, $heldAmount);
                } else {
                    // Сумма сборки > холда: ДОСПИСАНИЕ разницы ПЕРВЫМ (единственная
                    // операция, падающая по недостатку средств), capture холда вторым.
                    $extra = $shortfall;
                    $chargeResult = $paymentService->chargeExtra($order, $extra);

                    // Холд капчурим в любом исходе: при провале досписания мерчант
                    // получает хотя бы захолдированное (иначе холд истечёт у банка
                    // и не достанется ничего).
                    $paymentService->capturePayment($order->fresh(), $heldAmount);

                    if (! $chargeResult->success) {
                        // Недосбор больше не молчит: событие payment_failed делает
                        // заказ видимым в АП бейджем «Ошибка оплаты» (paid не
                        // трогаем — холд реально собран), оператор свяжется
                        // с покупателем по разнице вручную.
                        $failedOrder = $order->fresh();
                        $failedOrder->load('store');

                        \App\RabbitMQ\Debug\LoggingEventPublisher::dispatch(
                            entityType: 'orders',
                            model: $failedOrder,
                            eventType: 'order_payment_failed',
                            payload: [],
                            shopCode: $failedOrder->shopCodeForEvents()
                        );

                        Log::warning('Extra charge failed on assembled capture (legacy flow)', [
                            'order_id' => $order->id,
                            'extra' => $extra,
                            'error' => $chargeResult->errorMessage,
                        ]);
                    }
                }

                Log::info('Payment captured on assembly completion', [
                    'order_id' => $order->id,
                    'grand_total' => $grandTotal,
                    'held_amount' => $heldAmount,
                    'extra_charged' => $grandTotal > $heldAmount ? $grandTotal - $heldAmount : 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('Payment capture failed on assembly', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Применить позиции и суммы по итогам сборки из payload order.updated курьерки
     * вне консьюмера — для синхронного списания сборщиком (collector_payment):
     * delivery_back вызывает settle-payment раньше, чем событие дойдёт до витрины,
     * а capture сразу ставит чек прихода. Статус и прочие поля заказа не трогает:
     * их по-прежнему применит само событие (повторная синхронизация позиций
     * идемпотентна — событие «собрано» и так приходит дважды).
     *
     * @param  array<string, mixed>  $eventData
     */
    public function applyAssemblyItems(Order $order, array $eventData): void
    {
        if (empty($eventData['items'])) {
            return;
        }

        $this->syncItemsAndRecalc($order, $eventData);
    }

    /**
     * Выделенный блок sync items + пересчёт сумм + BonusService::recalculate.
     * Вызывается из afterUpdate() только при наличии $eventData['items'].
     *
     * @param  array<string, mixed>  $eventData
     */
    private function syncItemsAndRecalc(Order $order, array $eventData): void
    {

        /** @var Order $order */
        $currentSource = config('rabbitmq-events.source');
        $order->load('items');

        \DB::transaction(function () use ($order, $eventData, $currentSource) {
            $matchedItemIds = [];
            // Данные оригинальных товаров при замене (ключ — базовый ID без суффикса _origin)
            $replacedOrigins = [];

            foreach ($eventData['items'] as $itemData) {
                $itemSource = $itemData['source'] ?? null;
                $itemId = $itemData['id'] ?? null;

                // Определяем, является ли элемент оригиналом замены (суффикс _origin)
                $isOriginEntry = $itemId && str_ends_with((string) $itemId, '_origin');
                if ($isOriginEntry) {
                    $baseItemKey = $itemSource.':'.substr((string) $itemId, 0, -7);
                    $replacedOrigins[$baseItemKey] = [
                        'title' => $itemData['product_name'] ?? null,
                        'price' => isset($itemData['unit_price_effective'])
                            ? round((float) $itemData['unit_price_effective'], 2)
                            : (isset($itemData['unit_price']) ? round((float) $itemData['unit_price'], 2) : null),
                        'quantity' => isset($itemData['quantity']) ? (int) $itemData['quantity'] : null,
                    ];
                }

                $localItem = null;

                // 1. Если source совпадает с нашим, id — это локальный OrderItem ID
                if ($itemSource === $currentSource && $itemId) {
                    $candidate = $order->items->firstWhere('id', (int) $itemId);
                    if ($candidate && ! in_array($candidate->id, $matchedItemIds)) {
                        $localItem = $candidate;
                    }
                }

                // 2. Попытка через metadata
                if (! $localItem && $itemId && $itemSource) {
                    $localItemId = $this->resolveEntityId('order_items', (string) $itemId, $itemSource);
                    if ($localItemId) {
                        $candidate = $order->items->firstWhere('id', $localItemId);
                        if ($candidate && ! in_array($candidate->id, $matchedItemIds)) {
                            $localItem = $candidate;
                        }
                    }
                }

                // 3. Fallback: матчинг по названию товара (для случая когда metadata отсутствует)
                if (! $localItem && ! empty($itemData['product_name'])) {
                    $localItem = $order->items
                        ->whereNotIn('id', $matchedItemIds)
                        ->firstWhere('title', $itemData['product_name']);
                }

                if (! $localItem) {
                    $itemStatus = $itemData['item_status'] ?? null;

                    if ($itemStatus && in_array($itemStatus, ['added', 'confirmed', 'replaced'])) {
                        $productId = null;
                        $productName = $itemData['product_name'] ?? 'Unknown';

                        if (! empty($itemData['product_id']) && ! empty($itemData['product_source'])) {
                            $productId = $this->resolveEntityId('products', (string) $itemData['product_id'], $itemData['product_source']);
                        }

                        // Fallback: поиск товара по названию в магазине заказа
                        $product = $productId ? \App\Models\Product::find($productId) : null;
                        if (! $product && $order->store_id) {
                            $product = \App\Models\Product::where('store_id', $order->store_id)
                                ->where('title', $productName)
                                ->first();
                            $productId = $product?->id;
                        }

                        $actualWeight = isset($itemData['actual_weight']) ? (int) $itemData['actual_weight'] : null;
                        $rawQuantity = (int) ($itemData['quantity'] ?? 1);
                        $price = round((float) ($itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? 0), 2);
                        $imageUrl = $product?->images?->first()?->path;

                        $productMuValue = $product?->measurement_unit?->value ?? 'piece';
                        $productStepValue = $product?->quantity_step ?? 1;

                        // Весовой путь только для весовых/объёмных товаров: для штучных
                        // actual_weight игнорируется (MID-246) — иначе quantity становился
                        // граммами («200 шт» у клиента), а total брался из line_total,
                        // пересчитанного delivery_back «по весу» (×200 к цене)
                        $isWeighted = in_array($productMuValue, ['gram', 'kilogram', 'liter', 'milliliter'], true);

                        if ($actualWeight !== null && $isWeighted) {
                            // actual_weight в наименьших единицах: граммы, у объёмных — мл
                            $measurementUnit = $this->smallestMeasurementUnit($productMuValue);
                            $quantity = $actualWeight;
                            $total = round((float) ($itemData['line_total'] ?? $price * $rawQuantity), 2);
                        } elseif ($itemStatus === 'replaced') {
                            // Строка «что было до замены»: delivery_back шлёт количество
                            // уже в наименьших единицах, значит и единица хранения
                            // наименьшая (MID-267). Денег позиция не несёт.
                            $quantity = $rawQuantity;
                            $total = round((float) ($itemData['line_total'] ?? 0), 2);
                            $measurementUnit = $this->smallestMeasurementUnit($productMuValue);
                        } else {
                            // Конвертация порций в единицы измерения (граммы/мл)
                            $step = $product?->quantity_step ?? 1;
                            $quantity = $step > 1 ? $rawQuantity * $step : $rawQuantity;
                            $total = round($price * $rawQuantity, 2);
                            $measurementUnit = $product?->measurement_unit?->value ?? 'piece';
                        }

                        // Для товаров-замен подтягиваем данные оригинала из _origin записи
                        $originInfo = [];
                        if ($itemStatus === 'added' && $itemId && $itemSource) {
                            $originKey = $itemSource.':'.(string) $itemId;
                            if (isset($replacedOrigins[$originKey])) {
                                $originInfo = [
                                    'origin_title' => $replacedOrigins[$originKey]['title'],
                                    'origin_price' => $replacedOrigins[$originKey]['price'],
                                    'origin_quantity' => $replacedOrigins[$originKey]['quantity'],
                                ];
                            }
                        }

                        // Снапшот eligible_for_bonus на момент появления item (replacement/supplement).
                        // Флаг фиксируется здесь и дальше уже не меняется — пересчёт бонусов
                        // оперирует только сохранённым флагом, без повторного обращения к Product.
                        //
                        // Корзины за такой позицией нет (её создал сборщик), поэтому снапшот
                        // скидки берём из оффера магазина заказа и тут же пишем в price_old —
                        // иначе пересчёты промокода и payload увидели бы позицию без скидки,
                        // хотя магазин продал её со скидкой. В легаси offerPriceOld() всегда
                        // null, и строка создаётся ровно как раньше.
                        $itemDiscount = app(\App\Services\Discount\ItemDiscountResolver::class);
                        $offerPriceOld = $itemDiscount->offerPriceOld($product, (int) $order->store_id);
                        $eligibleForBonus = $product
                            ? ! $itemDiscount->hasDiscountForProduct($product, $offerPriceOld)
                            : true;

                        $originProductId = isset($itemData['origin_product_id'])
                            ? $this->resolveEntityId(
                                'products',
                                (string) $itemData['origin_product_id'],
                                $itemData['origin_product_source']
                                    ?? $itemData['product_source']
                                    ?? config('rabbitmq-events.source')
                            )
                            : null;

                        $order->items()->create(array_merge([
                            'product_id' => $productId,
                            'title' => $productName,
                            'sku' => $product?->sku ?? '',
                            'price' => $price,
                            'price_old' => $offerPriceOld,
                            'quantity' => $quantity,
                            'returned_quantity' => (int) ($itemData['returned_quantity'] ?? 0),
                            'actual_weight' => $actualWeight,
                            'total' => $total,
                            'measurement_unit' => $measurementUnit,
                            // Без ставки позиция уходит в чек АТОЛ со ставкой из настроек
                            // интеграции (на проде «без НДС»), как в CreateOrderAction
                            'vat_rate' => $product?->vat_rate,
                            'status' => $itemStatus,
                            'image_url' => $imageUrl,
                            'eligible_for_bonus' => $eligibleForBonus,
                            'origin_product_id' => $originProductId,
                            'origin_title' => $itemData['origin_product_name'] ?? null,
                            'origin_price' => isset($itemData['origin_price']) ? round((float) $itemData['origin_price'], 2) : null,
                            'origin_quantity' => isset($itemData['origin_quantity']) ? (int) $itemData['origin_quantity'] : null,
                        ], $originInfo, $this->tierFieldsForCreatedItem(
                            $itemData,
                            (string) $itemStatus,
                            (int) $quantity,
                            (string) $measurementUnit,
                            $product,
                            (float) $price,
                            (int) $order->id
                        )));

                        Log::info('OrderEventTransformer::afterUpdate - created new item', [
                            'order_id' => $order->id,
                            'product_name' => $productName,
                            'item_status' => $itemStatus,
                        ]);
                    } else {
                        Log::debug('OrderEventTransformer::afterUpdate - item not resolved', [
                            'item_id' => $itemId,
                            'item_source' => $itemSource,
                            'product_name' => $itemData['product_name'] ?? null,
                        ]);
                    }

                    continue;
                }

                $matchedItemIds[] = $localItem->id;

                // Весовой товар: если передан actual_weight — записываем вес в граммах напрямую
                $actualWeight = isset($itemData['actual_weight']) ? (int) $itemData['actual_weight'] : null;
                $productMu = $localItem->product?->measurement_unit?->value ?? $localItem->measurement_unit?->value ?? 'piece';
                $productStep = $localItem->product?->quantity_step ?? 1;

                // Для штучных товаров actual_weight игнорируется (MID-246): иначе
                // quantity превращался в граммы («200 шт» у клиента), а total брался
                // из line_total, пересчитанного delivery_back «по весу» (×200 к цене)
                $isWeighted = in_array($productMu, ['gram', 'kilogram', 'liter', 'milliliter'], true);

                if ($actualWeight !== null && $isWeighted) {
                    // actual_weight в наименьших единицах: граммы, у объёмных — мл
                    $measurementUnit = $this->smallestMeasurementUnit($productMu);
                    $quantity = $actualWeight;
                    $price = round((float) ($itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? $localItem->price), 2);
                    // line_total уже пересчитан delivery_back по фактическому весу
                    $total = round((float) ($itemData['line_total'] ?? $localItem->total), 2);
                } else {
                    $measurementUnit = $productMu;
                    $rawQuantity = (int) ($itemData['quantity'] ?? $localItem->quantity);
                    $itemStatus = $itemData['item_status'] ?? null;

                    // Для replaced items delivery_back уже отправляет quantity в граммах —
                    // НЕ конвертируем повторно. line_total = 0 (товар заменён).
                    if ($itemStatus === 'replaced') {
                        $quantity = $rawQuantity;
                        // ...а раз количество в наименьших единицах, то и единица
                        // хранения наименьшая (MID-267): «кг» рядом с граммовым
                        // количеством давало «1000 кг» у курьера и позицию на
                        // 300 000 ₽ у клиента вместо «1 кг» за 300 ₽.
                        $measurementUnit = $this->smallestMeasurementUnit($productMu);
                        $price = round((float) ($itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? $localItem->price), 2);
                        $total = round((float) ($itemData['line_total'] ?? 0), 2);
                    } else {
                        // Входящий quantity из RabbitMQ — в порциях (buildItems отправляет quantity/step).
                        // Конвертируем обратно в единицы измерения (граммы/мл) для хранения в БД.
                        $step = $localItem->product?->quantity_step ?? 1;
                        $quantity = $step > 1 ? $rawQuantity * $step : $rawQuantity;
                        // unit_price_effective — реальная цена за единицу (delivery_back после
                        // сборки записывает line total в unit_price, а per-unit в unit_price_effective)
                        $price = round((float) ($itemData['unit_price_effective'] ?? $itemData['unit_price'] ?? $localItem->price), 2);

                        $supplementsAmount = $localItem->supplements()->sum('amount');
                        // total считаем через порции (price за порцию), а не через сырой quantity
                        $portions = $step > 1 ? $rawQuantity : $quantity;
                        $total = round(($price + $supplementsAmount) * $portions, 2);
                    }
                }

                $localItem->update(array_merge([
                    'quantity' => $quantity,
                    'returned_quantity' => (int) ($itemData['returned_quantity'] ?? 0),
                    'price' => $price,
                    'total' => $total,
                    'actual_weight' => $actualWeight,
                    'status' => $itemData['item_status'] ?? null,
                    'origin_product_id' => isset($itemData['origin_product_id'])
                        ? $this->resolveEntityId('products', (string) $itemData['origin_product_id'], $itemData['origin_product_source'] ?? $itemData['product_source'] ?? config('rabbitmq-events.source'))
                        : null,
                    // Для replaced: origin — это сам товар, product_name содержит его название
                    // Для added: origin_product_name содержит название заменённого товара
                    'origin_title' => $itemData['origin_product_name'] ?? (($itemData['item_status'] ?? null) === 'replaced' ? ($itemData['product_name'] ?? null) : null),
                    'measurement_unit' => $measurementUnit,
                    'origin_price' => isset($itemData['origin_price']) ? round((float) $itemData['origin_price'], 2) : null,
                    'origin_quantity' => isset($itemData['origin_quantity']) ? (int) $itemData['origin_quantity'] : null,
                ], $this->tierFieldsForSyncedItem(
                    $localItem,
                    $itemData,
                    (int) $quantity,
                    (string) $measurementUnit,
                    (float) $price,
                    (int) $order->store_id
                )));
            }

            // Пересчёт итогов заказа на основе активных позиций (без отменённых и заменённых)
            // whereNotIn в MySQL не включает NULL, поэтому нужен orWhereNull
            $activeItemsQuery = fn () => $order->items()->where(function ($q) {
                $q->whereNotIn('status', ['cancelled', 'replaced'])->orWhereNull('status');
            });
            $subtotal = round((float) $activeItemsQuery()->sum('total'), 2);
            // Считаем только корневые позиции (без дочерних добавок из номенклатуры).
            // whereNull('parent_id') применяется ТОЛЬКО к count() — subtotal берёт все активные.
            $itemsCount = $activeItemsQuery()->whereNull('parent_id')->count();

            // Пересчёт скидки по промокоду на основе нового subtotal
            $oldDiscount = $order->discount;
            $discount = $this->recalculateDiscount($order, $subtotal);

            // Пересчёт стоимости доставки (порог бесплатной доставки)
            $deliveryCost = $this->recalculateDeliveryCost($order, $subtotal, $eventData);

            // Сначала обновим subtotal/discount/delivery_cost (total временно неточный),
            // чтобы BonusService::recalculate опирался на актуальное состояние.
            $order->forceFill([
                'subtotal' => $subtotal,
                'discount' => $discount,
                'delivery_cost' => $deliveryCost,
                'items_count' => $itemsCount,
            ])->saveQuietly();

            // Обновить discount_amount в order_promocodes
            if ($discount !== $oldDiscount && $order->promocode) {
                $order->promocode->update(['discount_amount' => $discount]);
            }

            // Пересчёт бонусов (mirror-only): обновит orders.{eligible_amount,bonus_earned,bonus_spent},
            // order_bonuses.{eligible_amount,amount} БЕЗ списания с баланса.
            // Реальное списание происходит в applyOnAssembled при статусе Assembled.
            app(\App\Services\Bonus\BonusService::class)->recalculateMirror($order->fresh());

            // Актуализировать total с учётом пересчитанного bonus_spent.
            // Стоимость сборки (assembly_cost) — плоская надбавка, не зависит от
            // состава: обязана остаться в total (капчерится в YooKassa вместе с ним).
            $freshOrder = $order->fresh();
            $total = round($subtotal - $discount - (int) $freshOrder->bonus_spent + $deliveryCost + (int) ($freshOrder->assembly_cost ?? 0), 2);
            $freshOrder->forceFill([
                'total' => $total,
            ])->saveQuietly();

            Log::info('Order updated from assembly event', [
                'order_id' => $order->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'delivery_cost' => $deliveryCost,
                'bonus_spent' => (int) $freshOrder->bonus_spent,
                'bonus_earned' => (int) $freshOrder->bonus_earned,
                'eligible_amount' => (int) $freshOrder->eligible_amount,
                'total' => $total,
                'items_count' => $itemsCount,
            ]);
        });
    }

    /**
     * Цены от количества (MID-308): поля существующей позиции, пересчитываемые по
     * снапшоту её лесенки и новому количеству.
     *
     * Снапшот. Правило единое для любых позиций: в событии есть ключ price_tiers
     * (array_key_exists) → order_items.price_tiers перезаписывается значением из
     * события: валидный снапшот как есть, иначе null. Так курьерка переписывает
     * снапшот существующей позиции (purchase_back OrderShopUpdate: свежий из
     * лесенки нового магазина) либо снимает его (в новом магазине лесенки нет;
     * delivery_back снимает снапшот при замене товара). Ключа нет: снапшот
     * позиции не трогаем (сервисы общей БД ключ не шлют).
     *
     * Поля. Цена за единицу и сумма строки по-прежнему берутся из события; здесь
     * считаются только зачёркнутая цена и признак «без скидки» (по нему ниже
     * идут промокод и бонусы): по действующему снапшоту (уже новому, если его
     * перезаписали) и новым порциям. Снапшот снят событием: позиция дальше
     * обычная, поля считаются прежней логикой позиции без лесенки, той же, что у
     * позиции, созданной из события (offerPriceOld() + hasDiscountForProduct()).
     * Расхождение цены события с собственной котировкой пишется предупреждением.
     *
     * Локальный флаг модуля не читается: всё зависит только от наличия ключа в
     * событии и снапшота у позиции.
     *
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>  [] когда ни ключа в событии, ни снапшота у позиции нет
     */
    private function tierFieldsForSyncedItem(
        OrderItem $localItem,
        array $itemData,
        int $quantity,
        string $storageUnit,
        float $eventPrice,
        int $orderStoreId
    ): array {
        if ($localItem->parent_id !== null) {
            return [];
        }

        $fields = [];
        $keyPresent = array_key_exists('price_tiers', $itemData);
        $hadSnapshot = $localItem->price_tiers !== null;

        if ($keyPresent) {
            $snapshot = PriceTierSnapshot::fromArray($itemData['price_tiers']);
            $fields['price_tiers'] = $snapshot?->toArray();
        } else {
            $snapshot = PriceTierSnapshot::fromArray($localItem->price_tiers);
        }

        // Отменённая и заменённая позиция в составе заказа не участвует:
        // снапшот принимаем (или снимаем), цены не пересчитываем.
        if (in_array($itemData['item_status'] ?? null, OrderItem::INACTIVE_STATUSES, true)) {
            return $fields;
        }

        if ($snapshot === null) {
            // Снапшота не было и нет: позиция обычная, поля не трогаем (как раньше).
            if (! ($keyPresent && $hadSnapshot)) {
                return $fields;
            }

            // Снапшот снят событием: price_old / eligible_for_bonus, выставленные
            // по ступени, сбрасываются на значения обычной позиции.
            $itemDiscount = app(\App\Services\Discount\ItemDiscountResolver::class);
            $product = $localItem->product;
            $offerPriceOld = $itemDiscount->offerPriceOld($product, $orderStoreId);

            $fields['price_old'] = $offerPriceOld;
            $fields['eligible_for_bonus'] = $product
                ? ! $itemDiscount->hasDiscountForProduct($product, $offerPriceOld)
                : true;

            return $fields;
        }

        $portions = OrderItemPortions::fromStored($quantity, $storageUnit, $localItem->product);

        // MID-308: полный возврат позиции у двери (заказ 98 на dev) — по
        // событию доставлено 0 порций. Ни одна ступень лесенки не покрывает
        // 0, quote(0) откатывается на базовую цену/признак снапшота вместо
        // значений позиции со сборки — расхождение с djami (там price_old/
        // eligible_for_bonus не пересчитываются) плюс ложное предупреждение
        // о несовпадении цены. Зачёркнутую цену и признак не трогаем,
        // предупреждение не пишем; снапшот по ключу события уже принят выше.
        if ($portions <= 0.0) {
            return $fields;
        }

        $quote = $snapshot->quote($portions);

        $this->logPriceTierMismatch([
            'order_id' => $localItem->order_id,
            'order_item_id' => $localItem->id,
        ], $portions, $eventPrice, $quote);

        $fields['price_old'] = $quote->unitPriceOld;
        $fields['eligible_for_bonus'] = $quote->isEligible;

        return $fields;
    }

    /**
     * Цены от количества (MID-308, спека 9.4): расхождение цены события с
     * собственной котировкой по снапшоту — предупреждение, не исключение;
     * цену события при этом не подменяем (источник цены и суммы строки — само
     * событие). Общий блок для существующей позиции
     * ({@see self::tierFieldsForSyncedItem()}) и позиции, созданной курьеркой
     * ({@see self::tierFieldsForCreatedItem()}): первое место, где цену новой
     * позиции считает уже курьерка сама — там рассинхрон витрина ↔ курьерка ↔
     * чек мог бы возникнуть незамеченным. Один и тот же текст сообщения —
     * оба случая находятся одним grep по логам.
     *
     * @param  array<string, mixed>  $context  order_id плюс то, что
     *         идентифицирует позицию на момент вызова: order_item_id — для
     *         уже существующей, product_name — для создаваемой (у неё ещё
     *         нет id)
     */
    private function logPriceTierMismatch(array $context, float $portions, float $eventPrice, PriceTierQuote $quote): void
    {
        if (abs($quote->unitPrice - $eventPrice) < 0.01) {
            return;
        }

        Log::warning('Order item price differs from own price_tiers quote', array_merge($context, [
            'portions' => $portions,
            'event_price' => $eventPrice,
            'quoted_price' => $quote->unitPrice,
        ]));
    }

    /**
     * Цены от количества (MID-308): позицию создала курьерка (замена, добавление
     * сборщиком): она же построила снапшот лесенки и передала его ключом
     * price_tiers. Сохраняем как есть; зачёркнутая цена и признак «без скидки»
     * считаются по снапшоту. Ключа нет: позиция создаётся ровно как раньше.
     * Зависит только от наличия ключа в событии, локальный флаг не читается:
     * курьерка шлёт ключ лишь при включённом у неё модуле.
     *
     * Расхождение цены события с собственной котировкой по снапшоту (спека
     * 9.4) логируется тем же {@see self::logPriceTierMismatch()}, что и у
     * существующей позиции — цену события при этом не подменяем.
     *
     * @param  array<string, mixed>  $itemData
     * @return array<string, mixed>
     */
    private function tierFieldsForCreatedItem(
        array $itemData,
        string $itemStatus,
        int $quantity,
        string $storageUnit,
        ?Product $product,
        float $eventPrice,
        int $orderId
    ): array {
        if (! array_key_exists('price_tiers', $itemData)) {
            return [];
        }

        $snapshot = PriceTierSnapshot::fromArray($itemData['price_tiers']);

        if ($snapshot === null) {
            return [];
        }

        $fields = ['price_tiers' => $snapshot->toArray()];

        if (in_array($itemStatus, OrderItem::INACTIVE_STATUSES, true)) {
            return $fields;
        }

        $portions = OrderItemPortions::fromStored($quantity, $storageUnit, $product);

        // MID-308: то же правило, что у существующей позиции
        // (tierFieldsForSyncedItem) — 0 порций в событии не покрыты ни одной
        // ступенью, quote(0) даёт произвольный (базовый) результат.
        // Зачёркнутую цену и признак не переопределяем: остаются значения,
        // посчитанные при создании позиции (offerPriceOld/
        // hasDiscountForProduct), предупреждение не пишем.
        if ($portions <= 0.0) {
            return $fields;
        }

        $quote = $snapshot->quote($portions);

        $this->logPriceTierMismatch([
            'order_id' => $orderId,
            'product_name' => $itemData['product_name'] ?? null,
        ], $portions, $eventPrice, $quote);

        $fields['price_old'] = $quote->unitPriceOld;
        $fields['eligible_for_bonus'] = $quote->isEligible;

        return $fields;
    }

    /**
     * Установить таймер авто-отмены для pickup-заказа при переходе в статус assembled.
     *
     * Capture и push-уведомление уже выполнены выше в beforeUpdate().
     */
    private function handlePickupAssembled(Order $order): void
    {
        $store = $order->store ?? \App\Models\Store::find($order->store_id);

        if (! $store || ! $store->pickup_auto_cancel_enabled) {
            return;
        }

        $hasPerishable = $order->items()
            ->whereHas('product.categories', fn ($q) => $q->where('is_perishable', true))
            ->exists();

        $waitHours = $hasPerishable
            ? ($store->pickup_wait_perishable_hours ?? 1)
            : ($store->pickup_wait_regular_hours ?? 3);

        $graceHours = $store->pickup_grace_period_hours ?? 1;
        $expiresAt = now()->addHours($waitHours + $graceHours);

        $order->update(['pickup_timer_expires_at' => $expiresAt]);

        Log::info('Pickup auto-cancel timer set', [
            'order_id' => $order->id,
            'expires_at' => $expiresAt->toIso8601String(),
            'has_perishable' => $hasPerishable,
            'wait_hours' => $waitHours,
            'grace_hours' => $graceHours,
        ]);
    }

    /**
     * Пересчёт скидки по промокоду на основе нового subtotal.
     * Промокод применяется только к товарам без скидки (без price_old и без акций).
     */
    private function recalculateDiscount(Order $order, float $subtotal): float
    {
        $order->load('promocode');

        if (! $order->promocode) {
            return $order->discount;
        }

        $promocode = $order->promocode;

        $originalPromocode = \App\Models\Promocode::with(['products', 'categories'])
            ->find($promocode->original_id);

        if ($promocode->type === PromocodeType::Fixed) {
            if ($originalPromocode && $originalPromocode->binding_type !== 'all') {
                $order->load('items.product');
                $activeItems = $order->items->filter(fn ($item) => ! in_array($item->status, ['cancelled', 'replaced']));
                $eligibleSubtotal = $activeItems
                    ->filter(fn ($item) => $item->product && $originalPromocode->matchesProduct($item->product))
                    ->sum('total');

                return round(min((float) $promocode->value, (float) $eligibleSubtotal), 2);
            }

            return round(min((float) $promocode->value, $subtotal), 2);
        }

        // Для процентного промокода: скидка только на товары без скидки
        // (источник тот же, что у корзины и создания заказа —
        // {@see \App\Services\Discount\ItemDiscountResolver}).
        $order->load('items.product');
        $itemDiscount = app(\App\Services\Discount\ItemDiscountResolver::class);
        $activeItems = $order->items->filter(function ($item) {
            return ! in_array($item->status, ['cancelled', 'replaced']);
        });

        $eligibleSubtotal = $activeItems
            ->filter(function ($item) use ($originalPromocode, $itemDiscount) {
                if (! $item->product || $itemDiscount->hasDiscount($item)) {
                    return false;
                }

                return $originalPromocode ? $originalPromocode->matchesProduct($item->product) : true;
            })
            ->sum('total');

        if ((float) $eligibleSubtotal <= 0) {
            return 0;
        }

        return round($eligibleSubtotal * $promocode->value / 100, 2);
    }

    /**
     * Пересчёт стоимости доставки на основе нового subtotal.
     * Если subtotal превышает порог бесплатной доставки — обнуляем.
     * Иначе оставляем исходную стоимость (она была определена при создании заказа через API).
     */
    private function recalculateDeliveryCost(Order $order, float $subtotal, array $eventData = []): float
    {
        // После сборки доставку считает курьерка и присылает её в shipping_total.
        //
        // Собственный пересчёт витрины знает только общий порог магазина
        // (stores.free_delivery_from), тогда как заказ оформляется по порогу ЗОНЫ
        // (CreateOrderAction берёт deliveryInfo->freeDeliveryFrom из slots-api, а
        // хранится он в shop_zones курьерки). Прод-заказ 213 (11.09.2026): зона
        // «Поселок Нартан» — доставка 250 ₽, бесплатно от 5000 ₽; магазин —
        // от 2500 ₽. Витрина обнулила доставку на сумме 2559 ₽, клиент увидел
        // итог на 250 ₽ меньше того, что списано по сумме курьерки.
        if (array_key_exists('shipping_total', $eventData) && $eventData['shipping_total'] !== null) {
            return round((float) $eventData['shipping_total'], 2);
        }

        // Снимок условий доставки (MID-280): ступень по сумме после сборки, не дороже
        // цены оформления. Заказы до выкладки снимка не имеют и идут прежним путём.
        $snapshot = DeliveryPriceSnapshot::fromArray($order->delivery_price_snapshot);

        if ($snapshot !== null) {
            return (float) $snapshot->priceFor($subtotal);
        }

        $store = $order->store;

        if ($store && $store->free_delivery_from !== null && $subtotal >= $store->free_delivery_from) {
            return 0;
        }

        return (float) $order->delivery_cost;
    }

    /**
     * Маппинг статуса из внутреннего enum в формат RabbitMQ.
     */
    private function mapStatusToRabbit(OrderStatus $status): string
    {
        return match ($status) {
            // Черновик наружу не публикуется (OrderNotDraftCondition), ветка —
            // страховка от UnhandledMatchError при ручном/дебажном вызове.
            OrderStatus::Draft => 'created',
            OrderStatus::Pending => 'created',
            OrderStatus::Assembling => 'assembling',
            OrderStatus::Assembled => 'assembled',
            OrderStatus::InDelivery => 'in_delivery',
            OrderStatus::Delivered => 'delivered',
            OrderStatus::Cancelled => 'cancelled',
        };
    }

    /**
     * Маппинг статуса из формата CRM/RabbitMQ во внутренний enum.
     */
    private function mapStatusFromRabbit(string $crmStatus): OrderStatus
    {
        return match ($crmStatus) {
            'created' => OrderStatus::Pending,
            'assembling' => OrderStatus::Assembling,
            'assembled' => OrderStatus::Assembled,
            'in_delivery' => OrderStatus::InDelivery,
            'delivered' => OrderStatus::Delivered,
            'cancelled', 'returned' => OrderStatus::Cancelled,
            default => OrderStatus::Pending,
        };
    }

    /**
     * Преобразовать сумму в формат для RabbitMQ.
     * В БД суммы хранятся как decimal(12,2) — рубли с копейками (LASTOCHKI-JAMI-11200).
     */
    private function toRubles(float|int|string|null $amount): float
    {
        return round((float) $amount, 2);
    }

    /**
     * Наименьшая единица для крупной базовой (кг → гр, литр → мл).
     *
     * Витрина хранит весовые и объёмные позиции в наименьших единицах, и
     * delivery_back в таких же присылает количество заменённой позиции. Единица
     * товара из карточки («кг») к этому количеству неприменима: 1000 с меткой
     * «кг» читается как тонна (MID-267). Штучные и уже наименьшие единицы
     * возвращаются без изменений.
     */
    private function smallestMeasurementUnit(string $unit): string
    {
        return match ($unit) {
            'kilogram' => 'gram',
            'liter' => 'milliliter',
            default => $unit,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClientInfo(Order $order): array
    {
        return [
            'name' => $order->user?->first_name ?? '',
            'surname' => $order->user?->last_name ?? '',
            'phone' => $order->user?->phone,
            'email' => $order->user?->email,
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function buildItems(Order $order): array
    {
        // Eager load supplements и product если не загружены
        if (! $order->items->first()?->relationLoaded('supplements')) {
            $order->load('items.supplements.supplement', 'items.supplements.values');
        }
        if (! $order->items->first()?->relationLoaded('product')) {
            $order->load('items.product.promotions');
        }

        // Подгрузить promotions для hasDiscount() проверки
        if ($order->items->first()?->product && ! $order->items->first()->product->relationLoaded('promotions')) {
            $order->load('items.product.promotions');
        }

        // Скидка позиции считается тем же источником, что в корзине и заказе
        // ({@see \App\Services\Discount\ItemDiscountResolver}).
        $itemDiscount = app(\App\Services\Discount\ItemDiscountResolver::class);

        // Bulk-загрузка metadata для всех товаров (предотвращение N+1)
        $productIds = $order->items->pluck('product_id')->unique()->filter();

        $productMetas = EventSyncMetadata::where('entity_type', 'products')
            ->whereIn('entity_id', $productIds)
            ->get()
            ->keyBy('entity_id');

        return $order->items->map(function ($item) use ($productMetas, $itemDiscount) {
            $productMeta = $productMetas->get($item->product_id);

            $step = $item->product?->quantity_step ?? 1;
            // Для крупных базовых единиц (кг/литр) без кванта quantity хранится в
            // наименьших единицах (граммы/мл, 1000 = 1 кг/л), delivery_back ожидает
            // порции (1 для 1 кг/л). Конвертируем обратно.
            $storageUnit = $item->measurement_unit?->value ?? $item->product?->measurement_unit?->value ?? 'piece';
            $productUnit = $item->product?->measurement_unit?->value ?? 'piece';
            if (in_array($storageUnit, ['gram', 'milliliter'], true) && in_array($productUnit, ['kilogram', 'liter'], true) && $step <= 1) {
                $portions = max(1, (int) round($item->quantity / 1000));
            } else {
                $portions = $step > 1 ? intdiv($item->quantity, $step) : $item->quantity;
            }

            // Цены от количества (MID-308, дополнение по ревью задачи 8): у позиции
            // СО снапшотом лесенки порции для исходящего события считаются
            // округлением (OrderItemPortions), как при сборке курьеркой, а не
            // отбрасыванием дробной части (formula выше): 1930 г при шаге 200 г
            // должны дать 10 порций, а не 9. Формула для позиций без снапшота
            // (действует и на форках, где снапшотов нет) не меняется.
            $tierSnapshot = $item->price_tiers !== null ? PriceTierSnapshot::fromArray($item->price_tiers) : null;
            if ($tierSnapshot !== null) {
                $portions = (int) OrderItemPortions::of($item);
            }

            $itemData = [
                'id' => (string) $item->id,
                'source' => config('rabbitmq-events.source'),
                'product_id' => $productMeta?->external_id ?? (string) $item->product_id,
                'product_source' => $productMeta?->source ?? config('rabbitmq-events.source'),
                'product_name' => $item->title,
                'unit_price' => $this->toRubles($item->price),
                'unit_price_effective' => $this->toRubles($item->price),
                'unit_price_old' => $item->price_old !== null ? $this->toRubles($item->price_old) : null,
                'line_subtotal' => $this->toRubles($item->total),
                'line_discount_total' => 0.0,
                'line_total' => $this->toRubles($item->total),
                'quantity' => $portions,
                'is_eligible' => $item->product ? ! $itemDiscount->hasDiscount($item) : true,
                // Связь дочерних позиций (товарная добавка из номенклатуры,
                // LASTOCHKI-JAMI-11211). line_id — стабильный ключ строки в рамках
                // payload; parent_line_id — line_id корневой позиции для дочерних.
                // source_supplement_value_external передаёт исходный supplement_value
                // как (string) локального id — у storefront нет external metadata для
                // supplement_values, admin резолвит локальный value по product+parent.
                'line_id' => (string) $item->id,
                'parent_line_id' => $item->parent_id ? (string) $item->parent_id : null,
                'source_supplement_value_external' => $item->source_supplement_value_id
                    ? (string) $item->source_supplement_value_id
                    : null,
            ];

            // Цены от количества (MID-308): кто создал позицию, тот и передаёт
            // снапшот её лесенки; курьерка сохранит его как есть и дальше считает
            // цену по нему. Зависит только от наличия снапшота у позиции, флаг не
            // читается: новые снапшоты строятся лишь при включённом модуле, а заказ
            // «в полёте» обязан досчитаться одинаково во всех сервисах. У позиций
            // без лесенки ключа нет.
            if ($tierSnapshot !== null) {
                $itemData['price_tiers'] = $tierSnapshot->toArray();
            }

            // Добавки
            if ($item->relationLoaded('supplements') && $item->supplements->isNotEmpty()) {
                $itemData['supplements'] = $item->supplements->map(function ($ois) {
                    $supplement = $ois->relationLoaded('supplement') ? $ois->supplement : null;

                    return [
                        'supplement_id' => $ois->supplement_id,
                        'title' => $supplement?->title ?? '',
                        'amount' => (int) $ois->amount,
                        'values' => $ois->relationLoaded('values')
                            ? $ois->values->map(fn ($v) => [
                                'supplement_value_id' => $v->id,
                                'title' => $v->title,
                                'amount' => (int) $v->pivot->amount,
                            ])->toArray()
                            : [],
                    ];
                })->toArray();
            }

            return $itemData;
        })->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAddress(Order $order): ?array
    {
        $address = $order->address;

        if (! $address) {
            return null;
        }

        return [
            'address' => $address->full_address,
            'entrance' => $address->entrance,
            'intercom' => $address->intercom,
            'floor' => $address->floor,
            'flat' => $address->apartment,
            'latitude' => $address->latitude ? (float) $address->latitude : null,
            'longitude' => $address->longitude ? (float) $address->longitude : null,
            'is_object' => $address->object_data !== null,
            'object_data' => $address->object_data ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPromocode(Order $order): ?array
    {
        $promocode = $order->promocode;

        if (! $promocode) {
            return null;
        }

        return [
            'code' => $promocode->code,
            'discount_percent' => $promocode->type?->value === 'percent' ? $promocode->value : null,
            // Тип и номинал — снапшот для курьерки: по ним AssemblyFinish
            // пересчитывает скидку после сборки теми же правилами, что и здесь
            // (fixed → min(номинал, сумма товаров), percent → процент от eligible).
            // Без них фиксированный промокод терялся при сплите по зонам сборки.
            'discount_type' => $promocode->type?->value,
            'discount_value' => $promocode->value,
        ];
    }

    /**
     * Начислить агрегированные бонусы за marketplace client_order (M.14=a).
     *
     * Вызывается когда sub-order переходит в статус `delivered`. Консолидированный
     * статус client_order'а вычисляется через ClientOrderStatusAggregator. Пока
     * не все sub-orders в delivered — ничего не делаем (ждём остальных). Когда
     * все доставлены — создаём одну earn транзакцию с `client_order_id`,
     * `order_id = null` и суммой bonus_earned по всем sub-orders.
     *
     * Идемпотентность: проверяем `client_order_id + type=earn` exists.
     */
    private function handleDeliveredSubOrder(Order $order): void
    {
        $clientOrderId = (string) $order->client_order_id;

        $consolidatedDelivered = DB::transaction(function () use ($order, $clientOrderId): bool {
            // Свежая выборка, чтобы учесть только что обновлённый статус sub-order'а
            $subOrders = Order::query()
                ->where('client_order_id', $clientOrderId)
                ->get();

            if ($subOrders->isEmpty()) {
                return false;
            }

            $statuses = $subOrders
                ->map(fn (Order $o) => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status))
                ->all();

            $consolidated = app(\App\Services\Order\ClientOrderStatusAggregator::class)->aggregate($statuses);

            if ($consolidated !== OrderStatus::Delivered) {
                // Ещё рано — хотя бы один sub-order не в terminal delivered состоянии.
                return false;
            }

            // Идемпотентность: одна earn транзакция на client_order
            $alreadyEarned = \App\Models\BonusTransaction::query()
                ->where('client_order_id', $clientOrderId)
                ->where('type', 'earn')
                ->exists();

            if ($alreadyEarned) {
                return true;
            }

            $totalEarned = (int) $subOrders->sum('bonus_earned');

            if ($totalEarned <= 0) {
                return true;
            }

            $user = \App\Models\User::lockForUpdate()->findOrFail($order->user_id);
            $user->bonus_balance += $totalEarned;
            $user->save();

            \App\Models\BonusTransaction::create([
                'user_id' => $user->id,
                'order_id' => null,
                'client_order_id' => $clientOrderId,
                'type' => 'earn',
                'amount' => $totalEarned,
                'balance_after' => $user->bonus_balance,
                'description' => "Начисление за клиентский заказ {$clientOrderId}",
                'created_at' => now(),
            ]);

            return true;
        });

        // Консолидированная финализация (P2.6): capture delivered sum, refund диффа,
        // пометить orders.client_order_finalized_at. Идемпотентно через finalized_at.
        if ($consolidatedDelivered) {
            app(\App\Actions\Order\FinalizeClientOrderAction::class)->execute($clientOrderId);
        }
    }
}
