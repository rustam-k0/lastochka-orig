<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ShippingMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\Store;
use App\Services\Order\ClientOrderStatusAggregator;
use App\Support\WeightAggregator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Агрегированный ответ marketplace client_order'а.
 *
 * Resource оборачивает коллекцию sub-Order'ов с одним `client_order_id`. Все
 * поля «уровня client_order» (shipping/payment/comment/recipient/...) берутся
 * с первого sub-order'а либо derive'ятся из связанных таблиц, без отдельной
 * `client_orders` модели — это сознательный Вариант A из marketplace-model.md §3.1.
 *
 * Поля под продакта (recipient, additionalServices, vatAmount) пока возвращаем
 * null/пустыми — после ответов продакта добавятся миграции и логика без
 * изменения shape (только заполнение).
 */
final class ClientOrderResource extends JsonResource
{
    public static $wrap = null;

    private string $clientOrderId;

    /** @var Collection<int, Order> */
    private Collection $orders;

    /**
     * @param  Collection<int, Order>  $orders
     */
    public function __construct(string $clientOrderId, Collection $orders)
    {
        // Resource оборачивает виртуальную сущность — не Eloquent-модель,
        // поэтому передаём в parent сами orders как «ресурс».
        parent::__construct($orders);

        $this->clientOrderId = $clientOrderId;
        $this->orders = $orders;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $orders = $this->orders;
        $first = $orders->first();

        $allItems = $orders->flatMap(fn (Order $o) => $o->items);

        $statuses = $orders
            ->map(fn (Order $o) => $o->status instanceof OrderStatus
                ? $o->status
                : OrderStatus::from((string) $o->status))
            ->all();

        $consolidatedStatus = (new ClientOrderStatusAggregator)->aggregate($statuses);

        $paymentTransactions = PaymentTransaction::query()
            ->where('client_order_id', $this->clientOrderId)
            ->orderBy('created_at')
            ->get();

        return [
            // Backward-compatible поля (P0/P1 контракт).
            'clientOrderId' => $this->clientOrderId,
            'subOrderIds' => $orders->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'consolidatedStatus' => $consolidatedStatus->value,
            'subtotal' => round((float) $orders->sum('subtotal'), 2),
            'discount' => round((float) $orders->sum('discount'), 2),
            'deliveryCost' => round((float) $orders->sum('delivery_cost'), 2),
            'assemblyCost' => round((float) $orders->sum('assembly_cost'), 2),
            'totalAmount' => round((float) $orders->sum('total'), 2),
            'itemsCount' => $allItems->count(),
            'items' => OrderItemResource::collection(\App\Support\OrderItemSorter::pairReplacementsWithOrigins($allItems)),
            'createdAt' => optional($first?->created_at)->toIso8601String(),

            // Расширение под мобильный экран «Детали заказа».
            'shippingMethod' => $this->resolveShippingMethod($first),
            'address' => $first?->address ? new OrderAddressResource($first->address) : null,
            'paymentMethod' => $this->derivePaymentMethod($paymentTransactions, $first),
            'paymentStatus' => $this->derivePaymentStatus($paymentTransactions),
            'paymentDeclineReason' => $this->derivePaymentDeclineReason($paymentTransactions),
            'paidAt' => $this->derivePaidAt($paymentTransactions),
            'comment' => $first?->comment,
            'recipient' => null,
            'additionalServices' => [],
            'vatAmount' => null,
            'weightTotal' => $this->aggregateWeight($allItems),
            'isHeavy' => $orders->contains(fn (Order $o) => (bool) $o->is_heavy),
            'couriersCount' => (int) $orders->max('couriers_count'),
            'isJointDelivery' => (int) $orders->max('couriers_count') > 1,
            'bonusSpend' => (int) $orders->sum('bonus_spent'),
            'bonusEarn' => (int) $orders->sum('bonus_earned'),
            'promocode' => $this->aggregatePromocode($orders),
            'progressStatus' => $this->deriveProgressStatus($consolidatedStatus),
            'subOrders' => $this->buildSubOrders($orders),
        ];
    }

    private function resolveShippingMethod(?Order $first): ?string
    {
        if ($first === null) {
            return null;
        }

        $method = $first->shipping_method instanceof ShippingMethod
            ? $first->shipping_method
            : ($first->shipping_method !== null ? ShippingMethod::from((string) $first->shipping_method) : null);

        return $method?->value;
    }

    /**
     * Реальный paymentMethod вытаскивается из payment_transactions client_order'а
     * (Order.payment_type у sub-order'ов — намеренный 'cash' хак из CreateClientOrderAction).
     * Если транзакций нет — fallback на Order.payment_type первого sub'а.
     *
     * @param  Collection<int, PaymentTransaction>  $transactions
     */
    private function derivePaymentMethod(Collection $transactions, ?Order $first): ?string
    {
        if ($transactions->isNotEmpty()) {
            return 'card';
        }

        return $first?->payment_type?->value;
    }

    /**
     * Derive paymentStatus из коллекции PaymentTransaction.
     *
     * Приоритет: refunded > paid > hold > failed > pending.
     *
     * @param  Collection<int, PaymentTransaction>  $transactions
     */
    private function derivePaymentStatus(Collection $transactions): string
    {
        if ($transactions->isEmpty()) {
            return 'pending';
        }

        $hasSucceeded = static fn (PaymentTransactionType $type): bool => $transactions->contains(
            fn (PaymentTransaction $tx) => $tx->type === $type
                && $tx->status === PaymentTransactionStatus::Succeeded,
        );

        if ($hasSucceeded(PaymentTransactionType::Refund)) {
            return 'refunded';
        }

        if ($hasSucceeded(PaymentTransactionType::Capture)) {
            return 'paid';
        }

        if ($hasSucceeded(PaymentTransactionType::Hold)) {
            return 'hold';
        }

        $hasFailed = $transactions->contains(
            fn (PaymentTransaction $tx) => $tx->status === PaymentTransactionStatus::Canceled,
        );

        return $hasFailed ? 'failed' : 'pending';
    }

    /**
     * Машинный код причины отказа онлайн-оплаты (ЮKassa cancellation_details.reason,
     * напр. 'insufficient_funds') из последней отменённой hold-транзакции
     * client_order'а. Клиент опознаёт кейс по коду, а не по тексту. null — отказа
     * не было либо причина ЮKassa не указана.
     *
     * @param  Collection<int, PaymentTransaction>  $transactions
     */
    private function derivePaymentDeclineReason(Collection $transactions): ?string
    {
        return $transactions
            ->filter(fn (PaymentTransaction $tx) => $tx->type === PaymentTransactionType::Hold
                && $tx->status === PaymentTransactionStatus::Canceled)
            ->sortByDesc('created_at')
            ->first()?->decline_reason;
    }

    /**
     * @param  Collection<int, PaymentTransaction>  $transactions
     */
    private function derivePaidAt(Collection $transactions): ?string
    {
        $capture = $transactions
            ->filter(fn (PaymentTransaction $tx) => $tx->type === PaymentTransactionType::Capture
                && $tx->status === PaymentTransactionStatus::Succeeded)
            ->sortByDesc('created_at')
            ->first();

        return $capture?->created_at?->toIso8601String();
    }

    /**
     * Сумма веса всех items в кг. Делегирует расчёт на OrderItem::grams() —
     * приоритет actual_weight, fallback на product.weight × quantity.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    private function aggregateWeight(Collection $items): ?float
    {
        return WeightAggregator::toKg(WeightAggregator::sumGrams($items));
    }

    /**
     * Aggregate promocode block на client_order: первый ненулевой код + сумма скидок.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{code: string, discount: int}|null
     */
    private function aggregatePromocode(Collection $orders): ?array
    {
        $promocodes = $orders
            ->map(fn (Order $o) => $o->promocode)
            ->filter();

        if ($promocodes->isEmpty()) {
            return null;
        }

        $code = (string) $promocodes->first()->code;
        $discount = round((float) $promocodes->sum('discount_amount'), 2);

        return ['code' => $code, 'discount' => $discount];
    }

    /**
     * Степпер прогресса: текущий consolidatedStatus + список статусов в нужном
     * порядке. Cancelled выпадает из sequence — это терминал, фронт рисует
     * отдельный визуал «Отменён» вне степпера.
     *
     * @return array{current: string, sequence: array<int, string>}
     */
    private function deriveProgressStatus(OrderStatus $consolidated): array
    {
        return [
            'current' => $consolidated->value,
            'sequence' => [
                OrderStatus::Pending->value,
                OrderStatus::Assembling->value,
                OrderStatus::Assembled->value,
                OrderStatus::InDelivery->value,
                OrderStatus::Delivered->value,
            ],
        ];
    }

    /**
     * Разбивка по sub-order'ам: каждый магазин-партнёр со своим слотом и items.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function buildSubOrders(Collection $orders): array
    {
        return $orders->map(function (Order $order): array {
            $shippingMethod = $order->shipping_method instanceof ShippingMethod
                ? $order->shipping_method
                : ($order->shipping_method !== null
                    ? ShippingMethod::from((string) $order->shipping_method)
                    : null);

            return [
                'id' => (int) $order->id,
                'storeId' => (int) $order->store_id,
                'status' => ($order->status instanceof OrderStatus
                    ? $order->status
                    : OrderStatus::from((string) $order->status))->value,
                'subtotal' => round((float) $order->subtotal, 2),
                'deliveryCost' => round((float) $order->delivery_cost, 2),
                'assemblyCost' => round((float) $order->assembly_cost, 2),
                'total' => round((float) $order->total, 2),
                'deliverySlot' => [
                    'date' => $order->delivery_slot_start?->format('Y-m-d'),
                    'timeFrom' => $order->delivery_slot_start?->format('H:i'),
                    'timeTo' => $order->delivery_slot_end?->format('H:i'),
                    'display' => $order->delivery_slot_display,
                ],
                'pickupStore' => $shippingMethod === ShippingMethod::Pickup
                    ? $this->buildPickupStore($order->store)
                    : null,
                'items' => OrderItemResource::collection(\App\Support\OrderItemSorter::pairReplacementsWithOrigins($order->items)),
            ];
        })->values()->all();
    }

    /**
     * @return array{id: int, name: string|null, address: string|null, description: string|null}|null
     */
    private function buildPickupStore(?Store $store): ?array
    {
        if ($store === null) {
            return null;
        }

        return [
            'id' => (int) $store->id,
            'name' => $store->name,
            'address' => $store->address,
            'description' => $store->description,
        ];
    }
}
