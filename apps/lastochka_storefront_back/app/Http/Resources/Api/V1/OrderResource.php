<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\PaymentCardResource;
use App\Support\OrderItemSorter;
use App\Support\WeightAggregator;
use Illuminate\Http\Request;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Order
 */
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Только корневые позиции: дочерние (добавки из каталога) сворачиваются в supplements[].
        $rootItems = $this->resource->relationLoaded('items')
            ? $this->items->whereNull('parent_id')
            : null;
        $items = $rootItems !== null
            ? OrderItemSorter::pairReplacementsWithOrigins($rootItems)
            : null;

        return [
            'id' => (int) $this->id,
            'store' => new StoreResource($this->whenLoaded('store')),
            'status' => $this->status->value,
            'items' => $items !== null ? OrderItemResource::collection($items) : OrderItemResource::collection($this->whenLoaded('items')),
            'address' => new OrderAddressResource($this->whenLoaded('address')),
            'promocode' => new OrderPromocodeResource($this->whenLoaded('promocode')),
            'subtotal' => round((float) $this->subtotal, 2),
            'discount' => round((float) $this->discount, 2),
            'bonusEarned' => (int) $this->bonus_earned,
            'bonusSpent' => (int) $this->bonus_spent,
            'deliveryCost' => round((float) $this->delivery_cost, 2),
            'assemblyCost' => round((float) $this->assembly_cost, 2),
            'total' => round((float) $this->total, 2),
            'bonus' => new OrderBonusResource($this->whenLoaded('bonus')),
            'itemsCount' => (int) $this->items_count,
            // Совокупный вес заказа (кг) по корневым позициям — null, если вес недоступен.
            'weightTotal' => $rootItems !== null
                ? WeightAggregator::toKg(WeightAggregator::sumGrams($rootItems))
                : null,
            // Фича «Тяжёлый заказ» / «Совместная доставка» — симметрично ClientOrderResource.
            'isHeavy' => (bool) $this->is_heavy,
            'couriersCount' => (int) $this->couriers_count,
            'isJointDelivery' => (int) $this->couriers_count > 1,
            'deliverySlotTimeSlot' => $this->delivery_slot_display,
            'deliverySlotDate' => $this->delivery_slot_start ? $this->delivery_slot_start->format('Y-m-d') : null,
            'isPaid' => (bool) $this->is_paid,
            'paymentType' => $this->payment_type->value,
            'shippingMethod' => $this->shipping_method->value,
            'isB2b' => $this->when(
                app(ModuleConfigService::class)->isB2bEnabled(),
                fn (): bool => (bool) ($this->store?->is_b2b ?? false),
            ),
            'comment' => $this->comment,
            'checkoutOptions' => $this->checkout_options ?? [],
            'pickupTimerExpiresAt' => $this->pickup_timer_expires_at ? $this->pickup_timer_expires_at->toIso8601String() : null,
            // До какого момента черновик (status = draft) ждёт подтверждения
            // онлайн-оплаты. Не черновик — null.
            'awaitingPaymentUntil' => $this->status === \App\Enums\OrderStatus::Draft && $this->awaiting_payment_until
                ? $this->awaiting_payment_until->toIso8601String()
                : null,
            'storeAddress' => $this->when(
                $this->shipping_method === \App\Enums\ShippingMethod::Pickup,
                fn (): ?string => $this->store?->address ?? $this->store?->name
            ),
            /** @format date-time */
            'createdAt' => $this->created_at->toIso8601String(),
            'heldAmount' => $this->held_amount !== null ? (float) $this->held_amount : null,
            'capturedAmount' => $this->captured_amount !== null ? (float) $this->captured_amount : null,
            'refundedAmount' => $this->refunded_amount !== null ? (float) $this->refunded_amount : null,
            'surchargeAmount' => $this->surcharge_amount !== null ? (float) $this->surcharge_amount : null,
            // Машинный код причины отказа онлайн-оплаты (напр. 'insufficient_funds').
            // Заполняется в асинхронном WebView-флоу после webhook'а ЮKassa об отмене;
            // клиент опознаёт кейс по коду, а не по тексту. null — отказа не было.
            'paymentDeclineReason' => $this->whenLoaded(
                'declinedHoldTransaction',
                fn () => $this->declinedHoldTransaction?->decline_reason,
            ),
            'paymentCard' => $this->whenLoaded('paymentCard', fn () => new PaymentCardResource($this->paymentCard)),
        ];
    }
}
