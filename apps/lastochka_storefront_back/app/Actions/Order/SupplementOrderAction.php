<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Enums\MeasurementUnit;
use App\Enums\OrderStatus;
use App\Enums\PromocodeType;
use App\Enums\ShippingMethod;
use App\Events\Order\OrderSupplemented;
use App\Exceptions\EmptyCartException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\OrderCannotBeSupplementedException;
use App\Exceptions\OrderSupplementSlotExpiredException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSupplement;
use App\Models\OrderSupplementIdempotency;
use App\Models\User;
use App\RabbitMQ\Debug\LoggingEventPublisher;
use App\Services\Pricing\OrderItemPortions;
use App\Services\Pricing\PriceTierSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplementOrderAction
{
    public function __construct(
        private ReserveProductsAction $reserveProducts,
        private readonly \App\Services\Discount\ItemDiscountResolver $itemDiscount,
        private readonly \App\Services\Pricing\CartItemTierPricing $tierPricing,
    ) {}

    /**
     * Дополняет существующий заказ товарами из корзины.
     *
     * @throws EmptyCartException
     * @throws InsufficientStockException
     * @throws OrderCannotBeSupplementedException
     * @throws OrderSupplementSlotExpiredException
     */
    public function execute(Order $order, User $user, ?string $idempotencyKey = null): Order
    {
        // Проверка идемпотентности
        if ($idempotencyKey) {
            $existing = OrderSupplementIdempotency::where('idempotency_key', $idempotencyKey)
                ->where('user_id', $user->id)
                ->where('order_id', $order->id)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first();

            if ($existing) {
                return $order->fresh(['store', 'items.supplements.supplement', 'items.supplements.values', 'address', 'promocode']);
            }
        }

        $result = DB::transaction(function () use ($order, $user, $idempotencyKey) {
            // Блокируем заказ для обновления (race condition protection)
            $order = Order::lockForUpdate()->findOrFail($order->id);

            // Повторная проверка статуса внутри транзакции
            if (! $order->status->canSupplement()) {
                throw new OrderCannotBeSupplementedException($order->status);
            }

            // Повторная проверка слота доставки внутри транзакции — подстраховка
            // на случай, если слот истёк между авторизацией (OrderPolicy::supplement)
            // и захватом блокировки (ALD-130)
            if ($order->isDeliverySlotExpired()) {
                throw new OrderSupplementSlotExpiredException;
            }

            // Получить корзину пользователя
            $cart = Cart::where('user_id', $user->id)->first();

            if (! $cart) {
                throw new EmptyCartException;
            }

            // Получить товары для магазина заказа (с добавками)
            $cartItems = CartItem::where('cart_id', $cart->id)
                ->where('store_id', $order->store_id)
                ->with(['product', 'supplements.values'])
                ->get();

            if ($cartItems->isEmpty()) {
                throw new EmptyCartException;
            }

            // Товар «только самовывоз» нельзя дозаказать в заказ, доставка
            // которого не самовывоз — то же ограничение, что и при создании заказа
            // (CreateOrderAction), иначе оно обходится через дозаказ. isPickupOnly()
            // учитывает и товарный pickup_only, и per-shop стоп-лист «Не доставлять».
            if ($order->shipping_method !== ShippingMethod::Pickup && $cartItems->contains(fn (CartItem $item) => (bool) $item->product?->isPickupOnly())) {
                throw new ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => 'В заказе есть товар, доступный только для самовывоза',
                        'errors' => ['shippingMethod' => ['В заказе есть товар, доступный только для самовывоза']],
                    ], 422)
                );
            }

            // Цены от количества (MID-308): позиции корзины с лесенкой котируются
            // заново по своему количеству; снапшоты уйдут новым позициям заказа.
            // Флаг выключен: пустой массив, позиции не меняются.
            $tierSnapshots = $this->tierPricing->requote($cartItems);

            // Резервировать товары
            $this->reserveProducts->execute($cartItems);

            // Подготовить данные для добавленных товаров
            $addedItems = collect();

            // Обработать каждый товар из корзины
            foreach ($cartItems as $cartItem) {
                $product = $cartItem->product;

                // Построить ключ добавок для сравнения
                $cartSupplementsKey = $this->buildSupplementsKey($cartItem);

                // Попробовать найти существующий OrderItem с тем же товаром и набором добавок
                $existingItem = $this->findMatchingOrderItem($order, $cartItem->product_id, $cartSupplementsKey);

                $itemData = [
                    'product_id' => $cartItem->product_id,
                    'sku' => $product?->sku ?? '',
                    'title' => $product?->title ?? 'Товар',
                    'price' => $cartItem->price,
                    'quantity' => $cartItem->quantity,
                    'total' => $cartItem->price * $cartItem->portions,
                ];

                // Цены от количества (MID-308): у существующей позиции есть снапшот
                // лесенки: цена считается по НЕМУ и новому суммарному количеству.
                // Зависит только от наличия снапшота у позиции, локальный флаг не читается.
                $existingSnapshot = $existingItem !== null
                    ? PriceTierSnapshot::fromArray($existingItem->price_tiers)
                    : null;

                if ($existingItem && $existingSnapshot !== null) {
                    $this->mergeByTierSnapshot($existingItem, $cartItem, $existingSnapshot);
                } elseif ($existingItem) {
                    // Товар с таким же набором добавок уже есть — увеличиваем количество.
                    // Позиция хранит весовое в граммах, корзина товара «по кг» без
                    // кванта — в кг: 1000 г + 1 кг = 2000 г, а не 1001.
                    $storedInSmallest = in_array($existingItem->measurement_unit?->value, ['gram', 'milliliter'], true);
                    $existingItem->quantity += $storedInSmallest
                        ? OrderItemPortions::storedFromCart((int) $cartItem->quantity, $cartItem->product)
                        : (int) $cartItem->quantity;
                    $portions = OrderItemPortions::fromStored(
                        (int) $existingItem->quantity,
                        $existingItem->measurement_unit?->value,
                        $cartItem->product
                    );
                    $existingItem->total = round($existingItem->price * $portions, 2);
                    $existingItem->save();
                } else {
                    // Новый товар или другой набор добавок — создаём snapshot
                    $firstImage = $product?->images?->first();

                    // Как CreateOrderAction: количество в единице хранения позиции
                    // (кг корзины → граммы) и сама единица хранения.
                    $measurementUnit = $product?->measurement_unit ?? MeasurementUnit::Piece;
                    if ($measurementUnit === MeasurementUnit::Kilogram) {
                        $measurementUnit = MeasurementUnit::Gram;
                    }

                    $orderItem = $order->items()->create(array_merge([
                        'product_id' => $cartItem->product_id,
                        'title' => $product?->title ?? 'Товар',
                        'sku' => $product?->sku ?? '',
                        'price' => $cartItem->price,
                        'price_old' => $cartItem->price_old,
                        'quantity' => OrderItemPortions::storedFromCart((int) $cartItem->quantity, $product),
                        'measurement_unit' => $measurementUnit->value,
                        'total' => $cartItem->price * $cartItem->portions,
                        'image_url' => $firstImage?->path ?? null,
                        'created_at' => now(),
                    ], $this->tierFieldsForNewItem($cartItem, $tierSnapshots)));

                    // Копировать добавки
                    $this->copySupplementsToOrderItem($orderItem, $cartItem);
                }

                $addedItems->push($itemData);
            }

            // Пересчитать суммы заказа (с учётом добавок)
            $order->refresh();
            $order->load(['items.supplements.values', 'items.product', 'promocode']);

            $newSubtotal = $this->calculateOrderSubtotal($order);
            $newDiscount = $this->recalculateDiscount($order);
            $oldDiscount = $order->discount;
            // Стоимость сборки (assembly_cost) — плоская надбавка, сохраняется в total
            $newTotal = round($newSubtotal - $newDiscount + $order->delivery_cost + (int) ($order->assembly_cost ?? 0), 2);
            $newItemsCount = $order->items->count();

            $order->update([
                'subtotal' => $newSubtotal,
                'discount' => $newDiscount,
                'total' => $newTotal,
                'items_count' => $newItemsCount,
            ]);

            // Обновить discount_amount в order_promocodes
            if ($newDiscount !== $oldDiscount && $order->promocode) {
                $order->promocode->update(['discount_amount' => $newDiscount]);
            }

            // Очистить корзину для этого магазина
            CartItem::where('cart_id', $cart->id)
                ->where('store_id', $order->store_id)
                ->delete();

            // Сохранить идемпотентность
            if ($idempotencyKey) {
                OrderSupplementIdempotency::create([
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'expires_at' => now()->addHours(24),
                ]);
            }

            // Отправить внутреннее событие Laravel
            event(new OrderSupplemented($order, $addedItems));

            return $order;
        });

        // Загрузить store для публикации события (после коммита транзакции)
        $result->load('store');

        // Опубликовать событие order.order_supplemented в RabbitMQ (вне транзакции,
        // чтобы запись в outbox гарантированно сохранилась после коммита основной транзакции)
        LoggingEventPublisher::dispatch(
            entityType: 'orders',
            model: $result,
            eventType: 'order_supplemented',
            payload: [],
            shopCode: $result->shopCodeForEvents()
        );

        return $result->fresh(['store', 'items.supplements.supplement', 'items.supplements.values', 'address', 'promocode']);
    }

    /**
     * Цены от количества (MID-308): слияние с позицией, у которой есть снапшот
     * лесенки. Цена за порцию, зачёркнутая цена и признак «без скидки» считаются
     * по снапшоту ЭТОЙ позиции и новому суммарному количеству: правка цены в 1С
     * посреди заказа сумму не меняет.
     */
    private function mergeByTierSnapshot(OrderItem $existingItem, CartItem $cartItem, PriceTierSnapshot $snapshot): void
    {
        $existingItem->loadMissing('product');

        $portions = OrderItemPortions::of($existingItem) + (float) $cartItem->portions;
        $quote = $snapshot->quote($portions);

        // Позиция, созданная оформлением, хранит весовое количество в граммах;
        // корзина килограммового товара без кванта: в килограммах.
        $storedInSmallest = in_array($existingItem->measurement_unit?->value, ['gram', 'milliliter'], true);

        $existingItem->quantity += $storedInSmallest
            ? OrderItemPortions::storedFromCart((int) $cartItem->quantity, $cartItem->product)
            : (int) $cartItem->quantity;
        $existingItem->price = $quote->unitPrice;
        $existingItem->price_old = $quote->unitPriceOld;
        $existingItem->eligible_for_bonus = $quote->isEligible;
        $existingItem->total = round($quote->unitPrice * $portions, 2);
        $existingItem->save();
    }

    /**
     * Цены от количества (MID-308): свежий снапшот лесенки и признак «без скидки»
     * для новой позиции дозаказа. price / price_old / total позиции корзины уже
     * посчитаны по ступени (requote()). [] для позиций без лесенки: они
     * создаются ровно как раньше.
     *
     * @param  array<int, PriceTierSnapshot>  $tierSnapshots
     * @return array<string, mixed>
     */
    private function tierFieldsForNewItem(CartItem $cartItem, array $tierSnapshots): array
    {
        $snapshot = $tierSnapshots[(int) $cartItem->id] ?? null;

        if ($snapshot === null) {
            return [];
        }

        return [
            'price_tiers' => $snapshot->toArray(),
            'eligible_for_bonus' => ! $this->itemDiscount->hasDiscount($cartItem),
        ];
    }

    /**
     * Построить ключ добавок из CartItem.
     */
    private function buildSupplementsKey(CartItem $cartItem): string
    {
        if (! $cartItem->relationLoaded('supplements') || $cartItem->supplements->isEmpty()) {
            return '';
        }

        return $cartItem->supplements
            ->sortBy('supplement_id')
            ->map(function ($cis) {
                $valueIds = $cis->relationLoaded('values')
                    ? $cis->values->pluck('id')->sort()->implode(',')
                    : '';

                return $cis->supplement_id.':'.$valueIds;
            })
            ->implode('|');
    }

    /**
     * Построить ключ добавок из OrderItem.
     */
    private function buildOrderItemSupplementsKey(OrderItem $orderItem): string
    {
        if (! $orderItem->relationLoaded('supplements') || $orderItem->supplements->isEmpty()) {
            return '';
        }

        return $orderItem->supplements
            ->sortBy('supplement_id')
            ->map(function ($ois) {
                $valueIds = $ois->relationLoaded('values')
                    ? $ois->values->pluck('id')->sort()->implode(',')
                    : '';

                return $ois->supplement_id.':'.$valueIds;
            })
            ->implode('|');
    }

    /**
     * Найти OrderItem с тем же product_id и набором добавок.
     */
    private function findMatchingOrderItem(Order $order, int $productId, string $supplementsKey): ?OrderItem
    {
        $items = $order->items()
            ->where('product_id', $productId)
            ->with('supplements.values')
            ->get();

        foreach ($items as $item) {
            if ($this->buildOrderItemSupplementsKey($item) === $supplementsKey) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Копировать добавки из CartItemSupplement в OrderItemSupplement.
     */
    private function copySupplementsToOrderItem(OrderItem $orderItem, CartItem $cartItem): void
    {
        if (! $cartItem->relationLoaded('supplements') || $cartItem->supplements->isEmpty()) {
            return;
        }

        foreach ($cartItem->supplements as $cartItemSupplement) {
            $valuesTotal = $cartItemSupplement->relationLoaded('values')
                ? $cartItemSupplement->values->sum('price')
                : 0;

            $orderItemSupplement = OrderItemSupplement::create([
                'order_item_id' => $orderItem->id,
                'supplement_id' => $cartItemSupplement->supplement_id,
                'amount' => $valuesTotal,
            ]);

            if ($cartItemSupplement->relationLoaded('values')) {
                foreach ($cartItemSupplement->values as $value) {
                    $orderItemSupplement->values()->attach($value->id, [
                        'amount' => $value->price,
                    ]);
                }
            }
        }
    }

    /**
     * Пересчёт скидки по промокоду.
     * Промокод применяется только к товарам без скидки: без price_old позиции
     * (в сетевом каталоге это снапшот скидки оффера магазина) и без акций
     * ({@see \App\Services\Discount\ItemDiscountResolver}).
     */
    private function recalculateDiscount(Order $order): float
    {
        if (! $order->promocode) {
            return (float) $order->discount;
        }

        $promocode = $order->promocode;

        $originalPromocode = \App\Models\Promocode::with(['products', 'categories'])
            ->find($promocode->original_id);

        if ($promocode->type === PromocodeType::Fixed) {
            if ($originalPromocode && $originalPromocode->binding_type !== 'all') {
                $eligibleSubtotal = $order->items
                    ->filter(fn (OrderItem $item) => $item->product
                        && $originalPromocode->matchesProduct($item->product))
                    ->sum('total');

                return round(min((float) $promocode->value, (float) $eligibleSubtotal), 2);
            }
            $subtotal = $order->items->sum('total');

            return round(min((float) $promocode->value, (float) $subtotal), 2);
        }

        // Для процентного промокода: скидка только на товары без скидки
        $eligibleSubtotal = $order->items
            ->filter(function (OrderItem $item) use ($originalPromocode) {
                if (! $item->product || $this->itemDiscount->hasDiscount($item)) {
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
     * Рассчитать subtotal заказа с учётом добавок.
     */
    private function calculateOrderSubtotal(Order $order): float
    {
        return round((float) $order->items->sum(function (OrderItem $item) {
            $supplementsAmount = $item->relationLoaded('supplements')
                ? $item->supplements->sum('amount')
                : 0;

            return $item->total + ($supplementsAmount * $item->quantity);
        }), 2);
    }
}
