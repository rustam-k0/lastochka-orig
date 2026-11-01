<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\DTOs\Delivery\DeliveryInfoData;
use App\DTOs\Delivery\YandexPriceData;
use App\DTOs\Order\CreateOrderData;
use App\Enums\MeasurementUnit;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ShippingMethod;
use App\Enums\YandexClaimStatus;
use App\Events\Order\OrderCreated;
use App\Exceptions\DeliverySlotUnavailableException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidShippingAddressException;
use App\Exceptions\MinOrderAmountException;
use App\Exceptions\StoreAccessDeniedException;
use App\Exceptions\StoreClosedException;
use App\Exceptions\StoreInactiveException;
use App\Exceptions\YandexDeliveryException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemSupplement;
use App\Models\Product;
use App\Models\Store;
use App\Models\YandexDeliveryClaim;
use App\Services\Bonus\BonusService;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\ThirdPartyTariffPriceResolver;
use App\Services\Order\OrderObjectSnapshotter;
use App\Services\Pickup\PickupSlotService;
use App\Services\Pricing\DeliveryPriceLadder;
use App\Services\Pricing\DeliveryPriceSnapshot;
use App\Services\Settings\ModuleConfigService;
use App\Services\Store\AvailableStoreService;
use App\Services\YandexDelivery\YandexClaimService;
use App\Services\YandexDelivery\YandexGoClientInterface;
use App\Support\WeightAggregator;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CreateOrderAction
{
    public function __construct(
        private ReserveProductsAction $reserveProducts,
        private DeliveryService $deliveryService,
        private \App\Services\Payment\PaymentService $paymentService,
        protected YandexClaimService $yandexClaimService,
        protected YandexGoClientInterface $yandexGoClient,
        private PickupSlotService $pickupSlotService,
        private AvailableStoreService $availableStoreService,
        private BonusService $bonusService,
        private readonly OrderObjectSnapshotter $objectSnapshotter,
        private readonly ThirdPartyTariffPriceResolver $thirdPartyTariffPriceResolver,
        private readonly \App\Services\Delivery\CartProductsMapper $cartProductsMapper,
        private readonly \App\Services\ReadyTime\ReadyTimeResolver $readyTimeResolver,
        private readonly \App\Support\HeavyOrderResolver $heavyOrderResolver,
        private readonly \App\Services\Settings\ModuleConfigService $moduleConfig,
        private readonly \App\Services\Discount\ItemDiscountResolver $itemDiscount,
        private readonly \App\Services\Promocode\PromocodeUsageTracker $promocodeUsage,
        private readonly \App\Services\Pricing\CartItemTierPricing $tierPricing,
    ) {}

    /**
     * Создаёт заказ из корзины.
     *
     * @throws EmptyCartException
     * @throws MinOrderAmountException
     * @throws InsufficientStockException
     * @throws StoreAccessDeniedException
     * @throws YandexDeliveryException
     * @throws InvalidShippingAddressException
     */
    public function execute(CreateOrderData $data): array
    {
        // Проверка идемпотентности: если заказ с таким ключом уже существует, возвращаем его
        if ($data->idempotencyKey) {
            $existing = Order::where('idempotency_key', $data->idempotencyKey)
                ->where('user_id', $data->user->id)
                ->first();

            if ($existing) {
                return [
                    'orderId' => $existing->id,
                    'confirmationUrl' => null,
                ];
            }
        }

        // 1. Получить корзину пользователя
        $cart = Cart::where('user_id', $data->user->id)->first();

        if (! $cart) {
            throw new EmptyCartException;
        }

        // 2. Получить товары для указанного магазина (с добавками)
        $cartItems = CartItem::where('cart_id', $cart->id)
            ->where('store_id', $data->storeId)
            ->with(['product', 'supplements.values'])
            ->get();

        if ($cartItems->isEmpty()) {
            throw new EmptyCartException;
        }

        // 2.1. Цены от количества (MID-308): позиции с лесенкой котируются заново
        // по своему количеству ДО расчёта сумм: subtotal, скидка промокода, превью
        // бонусов и позиции заказа ниже читают уже цену ступени. Позиции без
        // лесенки остаются на снапшоте корзины. Флаг выключен: пустой массив,
        // позиции не меняются.
        $tierSnapshots = $this->tierPricing->requote($cartItems);

        // 3. Получить магазин и проверить активность
        $store = Store::findOrFail($data->storeId);

        if (! $store->is_active) {
            throw new StoreInactiveException($store->name);
        }

        // 3.1. B2B-гейт (зеркально Store::scopeForUserType): применяется только при
        // включённом модуле b2b; B2B-клиент заказывает в любых магазинах,
        // B2C-клиент — только в не-B2B (ALADDIN-10197).
        if (app(ModuleConfigService::class)->isB2bEnabled()) {
            $userIsB2b = $data->user->is_b2b ?? false;
            if (! $userIsB2b && $store->is_b2b) {
                throw new StoreAccessDeniedException;
            }
        }

        // 3.2. Проверки для самовывоза из магазина (не для ПВЗ сторонних служб)
        if ($data->isPickup()) {
            if (! $store->is_pickup_enabled) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => 'Самовывоз недоступен для этого магазина',
                        'errors' => ['shippingMethod' => ['Самовывоз недоступен для этого магазина']],
                    ], 422)
                );
            }

            if (! $data->skipPickupPaymentCheck) {
                $allowedPayments = is_array($store->pickup_payment_methods) ? $store->pickup_payment_methods : ['card'];
                $requestedPayment = $data->paymentType ?? 'cash';
                if (! in_array($requestedPayment, $allowedPayments)) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator: validator([], []),
                        response: response()->json([
                            'message' => 'Выбранный способ оплаты недоступен для самовывоза',
                            'errors' => ['paymentType' => ['Выбранный способ оплаты недоступен для самовывоза']],
                        ], 422)
                    );
                }
            }

            // Товары 18+ запрещено оплачивать онлайн — только на кассе при
            // получении. Сознательно НЕ под skipPickupPaymentCheck: правило
            // юридическое и действует и для клиентских заказов маркетплейса.
            if (($data->paymentType ?? 'cash') === PaymentType::Card->value
                && $cartItems->contains(fn (CartItem $item) => (bool) $item->product?->adult_only)) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => 'В заказе есть товары 18+, оплата возможна только на кассе при получении',
                        'errors' => ['paymentType' => ['В заказе есть товары 18+, оплата возможна только на кассе при получении']],
                    ], 422)
                );
            }
        }

        // 3.3. Товар «только самовывоз» блокирует все способы доставки кроме
        // самовывоза из магазина — курьера, Яндекс-доставку, СДЭК, Почту России.
        // isPickupOnly() читает поле карточки pickup_only.
        if (! $data->isPickup() && $cartItems->contains(fn (CartItem $item) => (bool) $item->product?->isPickupOnly())) {
            throw new \Illuminate\Validation\ValidationException(
                validator: validator([], []),
                response: response()->json([
                    'message' => 'В заказе есть товар, доступный только для самовывоза',
                    'errors' => ['shippingMethod' => ['В заказе есть товар, доступный только для самовывоза']],
                ], 422)
            );
        }

        // 4. Проверить минимальную сумму (с учётом добавок и зоны доставки)
        $subtotal = $this->calculateSubtotal($cartItems);

        if ($data->isPickup() || $data->isPickupPoint()) {
            $minAmount = $store->min_pickup_order_amount ?? $store->min_order_amount;
        } else {
            $zoneDeliveryInfo = $data->address
                ? $this->availableStoreService->getDeliveryInfo($data->address, $store)
                : null;
            $minAmount = $zoneDeliveryInfo
                ? $zoneDeliveryInfo['minOrderAmount']
                : $store->min_order_amount;
        }

        if ($minAmount && $subtotal < $minAmount) {
            throw new MinOrderAmountException(
                minAmount: $minAmount,
                currentAmount: $subtotal,
            );
        }

        // 5. Резервирование товаров перенесено внутрь транзакции создания заказа
        // (см. ниже), чтобы декремент дневного лимита fixed-товаров был атомарен:
        // при падении на валидации слота/адреса/оплате декремент откатывается, а не
        // оставляет orphan-списание дневного остатка без созданного заказа.

        // 6. Delivery info и слот — зависит от метода доставки.
        // Оформление без слота (модуль checkout_without_slots, MID-256): для
        // курьерской доставки deliverySlotId может отсутствовать — заказ уходит
        // «на ближайшее время», слот в заказе = NULL. Переданный слот учитывается
        // как раньше. ПВЗ (СДЭК/Почта) слота не имеет по своей природе — не считается.
        // Самовывоз в модуль не входит: время выдачи в магазине выбирает клиент,
        // слот там обязателен независимо от флага.
        $withoutSlot = $data->deliverySlotId === null
            && ! $data->isPickupPoint()
            && ! $data->isPickup();

        if ($withoutSlot) {
            if (! $this->moduleConfig->isCheckoutWithoutSlotsEnabled()) {
                // Защита от прямого вызова action без слота при выключенном модуле
                // (FormRequest требует слот, но action зовут и из других мест).
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'deliverySlotId' => ['Выберите время доставки'],
                ]);
            }

            // Слоты неявно ограничивали заказ графиком магазина — без слота
            // проверяем сами, что магазин сейчас принимает заказы.
            if (! $store->isOpenNow()) {
                throw new StoreClosedException($store->name);
            }
        }

        $pickupPointExternalId = null;
        if ($data->isPickupPoint()) {
            // СДЭК / Почта России: ПВЗ, слот не нужен
            $address = $data->address;
            $additional = $address->additional ?? [];

            if (($additional['kind'] ?? null) !== 'pickup') {
                throw new InvalidShippingAddressException('Адрес должен быть пунктом выдачи заказов (pickup).');
            }

            if (($additional['provider'] ?? null) !== $data->shippingMethod->value) {
                throw new InvalidShippingAddressException('Провайдер ПВЗ не совпадает с выбранным методом доставки.');
            }

            $pickupPointExternalId = isset($additional['external_id'])
                ? (string) $additional['external_id']
                : null;

            // Для ПВЗ используем фиксированный «слот» без даты (сейчас)
            $deliverySlot = new \App\DTOs\Delivery\DeliverySlotData(
                id: 0,
                timeSlot: '',
                startAt: now(),
                endAt: now(),
            );
            $shippingMethod = $data->shippingMethod;
            $deliveryInfo = null;
        } elseif ($data->isPickup()) {
            // Pickup: слот обязателен всегда (модуль checkout_without_slots на
            // самовывоз не распространяется). Guard от вызова action мимо
            // FormRequest — например из маркетплейсного CreateClientOrderAction.
            if ($data->deliverySlotId === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'deliverySlotId' => ['Выберите время самовывоза'],
                ]);
            }

            // Валидация слота по рабочим часам магазина
            $appTz = config('app.timezone');
            $slotDate = Carbon::createFromTimestamp($data->deliverySlotId, $appTz)->format('Y-m-d');
            // Fixed-готовность ежедневная — резолвим на дату слота, чтобы ранний
            // слот будущего дня тоже отклонялся (симметрично PickupSlotController).
            $availableSlots = $this->pickupSlotService->getSlots(
                $store,
                $slotDate,
                $this->readyTimeResolver->forCartItems(
                    $cartItems,
                    Carbon::createFromTimestamp($data->deliverySlotId, $appTz)->startOfDay(),
                ),
            );
            $matchingSlot = collect($availableSlots)->firstWhere('id', $data->deliverySlotId);

            if (! $matchingSlot) {
                throw new DeliverySlotUnavailableException;
            }

            $deliverySlot = new \App\DTOs\Delivery\DeliverySlotData(
                id: $data->deliverySlotId,
                timeSlot: $matchingSlot['timeSlot'],
                startAt: Carbon::createFromTimestamp($data->deliverySlotId, $appTz),
                endAt: Carbon::createFromTimestamp($data->deliverySlotId, $appTz)->addMinutes($store->slot_duration ?? 60),
            );
            $shippingMethod = ShippingMethod::Pickup;
            $deliveryInfo = null;
        } else {
            // Courier/Yandex: существующая логика без изменений.
            // Без слота delivery info запрашиваем на сегодня — цена доставки,
            // min_order, стоимость сборки и тип доставки зоны нужны и без слота.
            $slotDate = $withoutSlot
                ? now()->format('Y-m-d')
                : Carbon::createFromTimestamp($data->deliverySlotId, config('app.timezone'))->format('Y-m-d');
            // Слоты запрашиваем с товарами корзины — slots-api отсекает слоты,
            // на которые сборка не успевает (shops_categories.time_to_take)
            $slotProducts = $this->cartProductsMapper->map($cartItems);
            $deliveryInfo = $this->deliveryService->getDeliveryInfo($store, $data->address, $slotDate, $slotProducts);
            $deliverySlot = $withoutSlot
                ? null
                : $this->deliveryService->validateSlot($store, $data->address, $data->deliverySlotId, $slotProducts);

            $shippingMethod = $deliveryInfo->deliveryType === 'yandex'
                ? ShippingMethod::YandexDelivery
                : ShippingMethod::Courier;

            // Yandex checks (KEEP EXISTING CODE UNCHANGED)
            if ($shippingMethod === ShippingMethod::YandexDelivery) {
                // Заявке Яндекса нужен интервал (due): без слота берём ближайший
                // доступный (slots уже отфильтрованы enrichWithYandexDelivery по
                // max_due_hours) — клиент прячет выбор времени независимо от зоны.
                if ($withoutSlot) {
                    $deliverySlot = $deliveryInfo->slots[0] ?? null;
                    if ($deliverySlot === null) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'deliverySlotId' => ['По этому адресу сейчас нет доступного времени доставки'],
                        ]);
                    }
                }

                $paymentTypeForCheck = $data->paymentType ? PaymentType::from($data->paymentType) : PaymentType::Cash;
                if ($paymentTypeForCheck === PaymentType::Cash) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator: validator([], []),
                        response: response()->json([
                            'message' => 'При Яндекс-доставке оплата возможна только картой',
                            'errors' => ['paymentType' => ['При Яндекс-доставке оплата возможна только картой']],
                        ], 422)
                    );
                }

                $maxDueHours = config('yandex-delivery.max_due_hours', 4);
                if ($deliverySlot->startAt->gt(now()->addHours($maxDueHours))) {
                    throw new DeliverySlotUnavailableException;
                }
            }
        }

        // 7. Рассчитать промокод и стоимость доставки
        $cartPromocode = $cart->promocodes()->where('store_id', $data->storeId)->with('promocode')->first();
        [$discount, $eligibleAmount] = $this->calculateDiscount($cartItems, $cartPromocode);

        $yandexPrice = null;
        if ($data->isPickup()) {
            // Самовывоз из магазина — доставки нет.
            $deliveryCost = 0;
        } elseif ($data->isPickupPoint()) {
            // СДЭК / Почта России: цена тарифа от провайдера, через slots-api.
            $deliveryCost = $this->thirdPartyTariffPriceResolver->resolve(
                shopId: $data->storeId,
                shippingMethod: $data->shippingMethod,
                externalId: $pickupPointExternalId,
                tariffCode: $data->shippingTariffCode,
                weightGrams: WeightAggregator::sumGrams($cartItems),
            );
        } elseif ($shippingMethod === ShippingMethod::YandexDelivery) {
            $storeCoords = [(float) $store->longitude, (float) $store->latitude];
            $addressCoords = [(float) $data->address->longitude, (float) $data->address->latitude];
            // Режим заноса определяется адаптивно по адресу — тот же критерий, что
            // и в заявке (claim), чтобы оценка и факт совпадали. ALADDIN-11465.
            $skipDoorToDoor = \App\Services\YandexDelivery\YandexGoClient::shouldSkipDoorToDoor($data->address->house_number);
            // checkPrice нужен всегда — для создания заявки Яндекса (zone_id, оценка).
            $yandexPrice = $this->yandexGoClient->checkPrice($storeCoords, $addressCoords, $skipDoorToDoor);
            // Бесплатная доставка от порога применяется и к Яндексу — так же, как к
            // обычной курьерской (calculateDeliveryCost). При заказе ≥ freeDeliveryFrom
            // клиент за доставку не платит (магазин покрывает стоимость Яндекса).
            // Согласовано с отображением в корзине (CartCalculator::calculateDeliveryCost),
            // иначе клиент видит 0, а в заказе списывается полная цена Яндекса.
            $freeDeliveryFrom = $this->yandexFreeDeliveryFrom($store, $deliveryInfo);
            $deliveryCost = ($freeDeliveryFrom !== null && $subtotal >= $freeDeliveryFrom)
                ? 0
                : $yandexPrice->priceRounded();
        } else {
            $deliveryCost = $this->calculateDeliveryCost($store, $subtotal, $deliveryInfo);
        }

        // Снимок условий доставки (MID-280): после сборки и возврата курьерка считает
        // доставку по нему (ступень по факту, не дороже оформления), а не по живой зоне.
        $deliverySnapshot = $this->deliverySnapshot($store, $shippingMethod, $deliveryInfo, (float) $deliveryCost, $yandexPrice);

        // 7.5 Preview бонусов: выбор type/percent/requested и валидация баланса ДО транзакции.
        $bonusPreview = $this->previewBonus($data, $store, $cartItems, $cartPromocode);

        // Тяжёлый заказ (флоу «Тяжелый заказ»): вес корневых позиций корзины против
        // порога магазина. Дочерние позиции-добавки не учитываются — консистентно
        // с weightTotal в CartResource.
        $isHeavy = $this->heavyOrderResolver->isHeavyForItems(
            $store,
            $cartItems->whereNull('parent_id'),
        );

        // Стоимость сборки (модуль assembly_cost): зонная цена из slots-api
        // ($deliveryInfo->assemblyPrice) имеет приоритет над магазинной
        // $store->assembly_price. Зонный контекст ($deliveryInfo) есть только у
        // курьерской/Яндекс-доставки; при самовывозе/ПВЗ $deliveryInfo === null →
        // магазинная цена (текущее поведение). Не зависит от состава заказа.
        // 0 при выключенном флаге.
        $assemblyCost = $this->moduleConfig->isAssemblyCostEnabled()
            ? (int) ($deliveryInfo?->assemblyPrice ?? $store->assembly_price ?? 0)
            : 0;

        // Пре-recalculate total без bonus_spent — актуальный total заполним после BonusService::recalculate
        $totalBeforeBonus = round($subtotal - $discount + $deliveryCost + $assemblyCost, 2);

        // 8. Создать заказ и связанные данные в транзакции
        $paymentType = $data->paymentType ? PaymentType::from($data->paymentType) : PaymentType::Cash;
        $yandexClaim = null;

        // 7.7 Pre-authorization по сохранённой карте: синхронный холд ДО создания
        // заказа. При недостатке средств / отказе банка заказ вообще НЕ ложится в
        // БД — отдаём 422, ничего не резервируя. Для новой карты (redirect-флоу) и
        // наличных pre-auth не применяется — там холд после создания (шаг 17).
        $preauthResult = null;
        if ($paymentType === PaymentType::Card && $data->paymentCardId) {
            $savedCard = \App\Models\PaymentCard::query()
                ->where('id', $data->paymentCardId)
                ->where('user_id', $data->user->id)
                ->first();

            if ($savedCard && $savedCard->payment_method_id) {
                // Холдируем сумму ДО вычета бонусов: capture при сборке всегда
                // ≤ холда (бонусы только уменьшают итог), иначе YooKassa отклонит capture.
                $preauthResult = $this->paymentService->preauthorizeSavedCard(
                    user: $data->user,
                    amount: $totalBeforeBonus,
                    paymentMethodId: $savedCard->payment_method_id,
                    idempotencyKey: $data->idempotencyKey,
                );

                if (! $preauthResult->success) {
                    throw new HttpResponseException(response()->json([
                        'error' => 'payment_declined',
                        // Машинный код причины (ЮKassa cancellation_details.reason),
                        // чтобы клиент опознал кейс по коду, а не по тексту message.
                        'reason' => $preauthResult->declineReason,
                        'message' => $preauthResult->errorMessage ?? 'Не удалось списать средства с карты',
                    ], 422));
                }
            }
        }

        // 7.8 Черновик (модуль draft_orders_until_payment): онлайн-оплата новой
        // картой уходит на redirect-страницу ЮKassa, и до подтверждения холда
        // заказа для платформы не существует. Корзина сохраняется, промокод не
        // списывается, событие в курьерку не публикуется — всё это делает
        // FinalizeDraftOrderAction после оплаты.
        //
        // Не применяется к: наличным (подтверждать нечего), сохранённой карте
        // (pre-auth уже синхронно подтверждён выше) и sub-order'ам marketplace
        // (CreateClientOrderAction зовёт этот action с paymentType='cash',
        // холд там консолидированный).
        $isDraft = $paymentType === PaymentType::Card
            && $preauthResult === null
            && $this->moduleConfig->isDraftOrdersUntilPaymentEnabled();

        $awaitingPaymentUntil = $isDraft
            ? now()->addMinutes($this->moduleConfig->getDraftOrderTtlMinutes())
            : null;

        try {
            DB::transaction(function () use (
                $data, $cart, $cartItems, $deliverySlot, $shippingMethod,
                $paymentType, $subtotal, $discount, $deliveryCost, $deliverySnapshot, $assemblyCost, $totalBeforeBonus,
                $cartPromocode, $bonusPreview, $yandexPrice, $isHeavy, $isDraft, $awaitingPaymentUntil,
                $tierSnapshots,
                &$yandexClaim, &$order
            ) {
                // Резервирование внутри транзакции — декремент дневного лимита fixed
                // откатится вместе с заказом при любой ошибке создания.
                $this->reserveProducts->execute($cartItems);

                $pickupAddressData = null;
                $pickupExternalId = null;
                if ($data->isPickupPoint() && $data->address !== null) {
                    $additional = $data->address->additional ?? [];
                    $pickupExternalId = $additional['external_id'] ?? null;
                    $pickupAddressData = $additional ?: null;
                }

                $order = Order::create([
                    'user_id' => $data->user->id,
                    'store_id' => $data->storeId,
                    'source' => 'storefront',
                    'status' => $isDraft ? OrderStatus::Draft : OrderStatus::Pending,
                    'awaiting_payment_until' => $awaitingPaymentUntil,
                    // Позиции, вошедшие в черновик: при финализации удаляем именно
                    // их, а не всю корзину магазина — товар, добавленный клиентом
                    // во время оплаты, обязан остаться.
                    'draft_cart_item_ids' => $isDraft ? $cartItems->pluck('id')->all() : null,
                    'payment_type' => $paymentType,
                    'payment_card_id' => $data->paymentCardId,
                    'is_paid' => false,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'eligible_amount' => 0,
                    'delivery_cost' => $deliveryCost,
                    'delivery_price_snapshot' => $deliverySnapshot,
                    'assembly_cost' => $assemblyCost,
                    'total' => $totalBeforeBonus,
                    // Считаем только корневые позиции (без дочерних добавок из номенклатуры),
                    // чтобы клиент видел реальное число SKU в заказе, а не раздутое.
                    // Фильтр whereNull('parent_id') применяется ТОЛЬКО здесь — полный $cartItems
                    // по-прежнему нужен calculateSubtotal/createOrderItems для учёта добавок.
                    'items_count' => $cartItems->whereNull('parent_id')->count(),
                    'is_heavy' => $isHeavy,
                    // NULL — оформление без слота (модуль checkout_without_slots)
                    'delivery_slot_start' => $deliverySlot?->startAt,
                    'delivery_slot_end' => $deliverySlot?->endAt,
                    'delivery_slot_display' => $deliverySlot?->timeSlot,
                    'shipping_method' => $shippingMethod,
                    'comment' => $data->comment,
                    // Снапшот опций чекаута (code+label): переименование опции
                    // в админке не должно менять уже оформленные заказы
                    'checkout_options' => $data->checkoutOptions !== []
                        ? app(\App\Services\Checkout\CheckoutOptionsService::class)->snapshotFor($data->checkoutOptions)
                        : null,
                    'idempotency_key' => $data->idempotencyKey,
                    'bonus_earned' => 0,
                    'bonus_spent' => 0,
                    'shipping_tariff_code' => $data->isPickupPoint() ? $data->shippingTariffCode : null,
                    'pickup_external_id' => $pickupExternalId,
                    'pickup_address_data' => $pickupAddressData,
                    // Аналитика (LASTOCHKI-JAMI-11659): связка «поведение в
                    // приложении → заказ». Колонки, а не payload на лету:
                    // outbox-воркер пересобирает событие вне HTTP-контекста.
                    'analytics_session_id' => mb_substr((string) request()->header('X-Analytics-Session-Id', ''), 0, 64) ?: null,
                    'analytics_device_client_id' => mb_substr((string) request()->header('X-Analytics-Client-Id', ''), 0, 64) ?: null,
                ]);

                // 9. Создать snapshot адреса (только для доставки)
                if ($data->address) {
                    $this->createOrderAddress($order, $data->address);
                }

                // 10. Создать snapshot товаров с добавками (с флагом eligible_for_bonus)
                $this->createOrderItems($order, $cartItems, $tierSnapshots);

                // 11. Создать snapshot промокода
                if ($cartPromocode) {
                    $this->createOrderPromocode($order, $cartPromocode, $discount);

                    // 11.1 Зафиксировать использование промокода: +1 в usages и used_count.
                    // Per-client лимит учитывается именно при создании заказа, не при apply к корзине —
                    // чтобы отказ от оформления не "сжигал" попытку. У черновика оформлением
                    // считается подтверждение оплаты — списывает FinalizeDraftOrderAction.
                    if (! $isDraft) {
                        $this->promocodeUsage->track($order, $cartPromocode->promocode);
                    }
                }

                // 11.5 Создать snapshot бонусов (amount/eligible заполнит BonusService::recalculate)
                if ($bonusPreview !== null) {
                    $order->bonus()->create([
                        'type' => $bonusPreview['type'],
                        'percent' => $bonusPreview['percent'],
                        'eligible_amount' => 0,
                        'amount' => 0,
                        'requested_amount' => $bonusPreview['requested'],
                        'created_at' => now(),
                    ]);
                }

                // 12-13. Очистить корзину и промокод для этого магазина.
                // Черновик корзину не трогает: клиент, закрывший страницу оплаты,
                // должен вернуться к своим товарам (очистка — в FinalizeDraftOrderAction).
                if (! $isDraft) {
                    CartItem::where('cart_id', $cart->id)
                        ->where('store_id', $data->storeId)
                        ->delete();

                    $cart->promocodes()->where('store_id', $data->storeId)->delete();
                }

                // 14. Создать YandexDeliveryClaim если это Яндекс-доставка
                if ($shippingMethod === ShippingMethod::YandexDelivery) {
                    $yandexClaim = YandexDeliveryClaim::create([
                        'order_id' => $order->id,
                        'zone_id' => $yandexPrice->zoneId,
                        'status' => YandexClaimStatus::New,
                        'price' => $deliveryCost,
                        'due_at' => $deliverySlot->startAt,
                    ]);
                }

                // 14.5 Пересчитать зеркальные поля бонусов (без списания): заполняет
                // orders.{eligible_amount,bonus_earned,bonus_spent} и order_bonuses.{eligible_amount,amount}.
                $this->bonusService->recalculateMirror($order);
                // 14.5.1 Зарезервировать баллы: создаёт BonusHold + увеличивает users.bonus_reserved.
                // Фактическое списание (bonus_transactions) переносится на этап сборки.
                $this->bonusService->reserveOnCreate($order);

                // 14.6 Актуализировать total с учётом применённого bonus_spent
                $order->refresh();
                $order->forceFill([
                    'total' => $subtotal - $discount - (int) $order->bonus_spent + $deliveryCost + $assemblyCost,
                ])->saveQuietly();
            });
        } catch (\Throwable $e) {
            // Гонка двух запросов с одним idempotencyKey: конкурент уже создал заказ
            // (unique orders_user_idempotency_unique). Pre-auth по тому же idempotence-key
            // вернул ОДИН и тот же платёж ЮKassa — холд принадлежит заказу-победителю,
            // поэтому НЕ отменяем его, а возвращаем существующий заказ (идемпотентный повтор).
            if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException && $data->idempotencyKey) {
                $existing = Order::where('idempotency_key', $data->idempotencyKey)
                    ->where('user_id', $data->user->id)
                    ->first();
                if ($existing) {
                    return ['orderId' => $existing->id, 'confirmationUrl' => null];
                }
            }

            // Транзакция создания заказа откатилась уже после успешного pre-auth
            // холда — освобождаем заблокированные деньги, иначе они зависнут на карте.
            if ($preauthResult !== null && $preauthResult->paymentId !== null) {
                try {
                    $this->paymentService->cancelHoldByPaymentId($preauthResult->paymentId);
                } catch (\Throwable $cancelError) {
                    \Illuminate\Support\Facades\Log::error('Failed to cancel pre-auth hold after rollback', [
                        'payment_id' => $preauthResult->paymentId,
                        'error' => $cancelError->getMessage(),
                    ]);
                }
            }

            throw $e;
        }

        // 14.7 Зафиксировать pre-auth холд за созданным заказом — ДО Yandex/события,
        // чтобы возможная отмена ниже по стеку нашла транзакцию в БД и сняла холд.
        if ($preauthResult !== null) {
            try {
                $this->paymentService->attachPreauthorizedHold($order, $preauthResult, (float) $totalBeforeBonus);
            } catch (\Throwable $e) {
                // Холд в ЮKassa есть, но привязать его к заказу не удалось — без записи
                // в БД он осиротеет (ни отмена, ни capture его не найдут). Снимаем холд
                // и отменяем неоплачиваемый заказ.
                if ($preauthResult->paymentId !== null) {
                    try {
                        $this->paymentService->cancelHoldByPaymentId($preauthResult->paymentId);
                    } catch (\Throwable $cancelError) {
                        \Illuminate\Support\Facades\Log::error('Failed to cancel hold after attach failure', [
                            'order_id' => $order->id,
                            'payment_id' => $preauthResult->paymentId,
                            'error' => $cancelError->getMessage(),
                        ]);
                    }
                }
                app(\App\Actions\Order\CancelOrderAction::class)->execute($order);

                throw $e;
            }
        }

        // 15. Вызвать Yandex API после коммита транзакции
        if ($yandexClaim) {
            try {
                $this->yandexClaimService->createAndAcceptClaim($order, $yandexClaim);
                $yandexClaim->refresh();

                // Отправить обновление с актуальным claim_id и статусом
                \Comrades\RabbitMQEvents\Services\CustomEventPublisher::dispatch(
                    entityType: 'orders',
                    model: $order->fresh(),
                    eventType: 'order_yandex_updated',
                    shopCode: $store->code,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to create Yandex claim, cancelling order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                app(\App\Actions\Order\CancelOrderAction::class)->execute($order);
                throw new YandexDeliveryException('Не удалось создать заявку Яндекс-доставки');
            }
        }

        // 16. Отправить событие (refresh чтобы подтянуть актуальный статус yandexClaim).
        // За черновик событие выпустит FinalizeDraftOrderAction после оплаты.
        $order->refresh();
        if (! $isDraft) {
            event(new OrderCreated($order));
        }

        // 17. Если оплата картой — создать холд
        $confirmationUrl = null;

        if ($paymentType === PaymentType::Card && $preauthResult === null) {
            // Новая карта / redirect-флоу: холд после создания заказа.
            // Сохранённая карта уже захолдирована pre-auth + attachPreauthorizedHold выше.
            $paymentMethodId = null;
            if ($data->paymentCardId) {
                $card = \App\Models\PaymentCard::find($data->paymentCardId);
                $paymentMethodId = $card?->payment_method_id;
            }

            $paymentResult = $this->paymentService->createHold($order, $paymentMethodId, $data->returnUrl);
            $confirmationUrl = $paymentResult->confirmationUrl;
        }

        return [
            'orderId' => $order->id,
            'confirmationUrl' => $confirmationUrl,
        ];
    }

    /**
     * Preview бонусов до создания заказа: выбирает type/percent/requested
     * и валидирует, что списание вообще возможно.
     *
     * Никаких side-effects: фактический расчёт amount/eligible_amount и
     * применение к балансу — в BonusService::recalculate() уже после
     * создания Order и order_items (когда известен eligible_for_bonus на
     * per-item уровне).
     *
     * @return array{type: string, percent: int, requested: int}|null
     */
    private function previewBonus(
        CreateOrderData $data,
        Store $store,
        Collection $cartItems,
        mixed $cartPromocode
    ): ?array {
        if (! $store->is_bonus_enabled || $data->bonusAction === null) {
            return null;
        }

        // Зеркало логики BonusService::calculateEligibleAmount на уровне cart_items.
        // Нужно здесь чтобы отдать UX-ошибку до создания заказа при bonusAction=spend.
        $eligibleAmount = $cartPromocode
            ? 0
            : $cartItems
                ->filter(fn ($item) => $item->product && ! $this->itemDiscount->hasDiscount($item))
                ->sum(fn ($item) => $item->price * $item->portions);

        if ($data->bonusAction === 'earn') {
            if ($eligibleAmount === 0) {
                return null;
            }

            return [
                'type' => 'earn',
                'percent' => (int) ($store->bonus_earn_percent ?? 0),
                'requested' => 0,
            ];
        }

        // spend
        $percent = (int) ($store->bonus_spend_percent ?? 0);
        $maxAllowed = (int) floor($eligibleAmount * $percent / 100);
        $requested = (int) ($data->bonusSpendAmount ?? 0);
        $userBalance = (int) ($data->user->available_bonus_balance ?? 0);
        $actualSpend = min($requested, $maxAllowed, $eligibleAmount);

        if ($actualSpend <= 0) {
            throw new HttpResponseException(
                response()->json(['error' => 'bonus_spend_failed', 'message' => 'Невозможно списать бонусы'], 422)
            );
        }

        if ($actualSpend > $userBalance) {
            throw new HttpResponseException(
                response()->json(['error' => 'insufficient_bonus_balance'], 422)
            );
        }

        return [
            'type' => 'spend',
            'percent' => $percent,
            'requested' => $requested,
        ];
    }

    /**
     * Subtotal с учётом добавок.
     *
     * item_total = (item.price + sum(selected_supplement_values.price)) * item.quantity
     */
    private function calculateSubtotal(Collection $cartItems): float
    {
        $subtotal = $cartItems->sum(function (CartItem $item) {
            $supplementsPrice = $item->relationLoaded('supplements')
                ? $item->supplements->sum(fn ($cis) => $cis->relationLoaded('values') ? $cis->values->sum('price') : 0)
                : 0;

            $itemTotal = ($item->price + $supplementsPrice) * $item->portions;

            return $itemTotal;
        });

        return round((float) $subtotal, 2);
    }

    /**
     * @return array{0: float, 1: float} [discount, eligibleAmount]
     */
    private function calculateDiscount(Collection $cartItems, $cartPromocode): array
    {
        if (! $cartPromocode || ! $cartPromocode->promocode) {
            return [0, 0];
        }

        $promocode = $cartPromocode->promocode;
        $promocode->loadMissing(['products', 'categories']);

        // Промокод применяется только к товарам без скидки (ручной, скидки
        // оффера магазина в сетевом каталоге или акционной).
        // Добавки НЕ участвуют в промокоде
        $eligibleSubtotal = $cartItems
            ->filter(fn ($item) => $item->product
                && ! $this->itemDiscount->hasDiscount($item)
                && $promocode->matchesProduct($item->product))
            ->sum(fn ($item) => $item->price * $item->portions);

        $eligibleSubtotal = round((float) $eligibleSubtotal, 2);

        if ($eligibleSubtotal <= 0) {
            return [0, 0];
        }

        if ($promocode->type->value === 'percent') {
            return [round($eligibleSubtotal * $promocode->value / 100, 2), $eligibleSubtotal];
        }

        return [round(min((float) $promocode->value, $eligibleSubtotal), 2), $eligibleSubtotal];
    }

    private function calculateDeliveryCost(Store $store, float $subtotal, ?DeliveryInfoData $deliveryInfo = null): float
    {
        return (float) $this->deliveryLadder($store, $deliveryInfo)->priceFor($subtotal);
    }

    /**
     * Лесенка стоимости доставки своих курьеров (MID-280): ступени зоны из slots-api.
     * Зона без ступеней и без порога берёт порог магазина, как прежний расчёт.
     */
    private function deliveryLadder(Store $store, ?DeliveryInfoData $deliveryInfo): DeliveryPriceLadder
    {
        if ($deliveryInfo === null) {
            return $store->deliveryLadder();
        }

        if ($deliveryInfo->deliveryPriceRanges !== []) {
            return $deliveryInfo->ladder();
        }

        return DeliveryPriceLadder::fromFreeDeliveryFrom(
            $deliveryInfo->deliveryPrice,
            $deliveryInfo->freeDeliveryFrom ?? $store->free_delivery_from,
        );
    }

    /**
     * Снимок условий доставки заказа (MID-280): свои курьеры — лесенка зоны; Яндекс —
     * цена Яндекса и только бесплатная ступень; самовывоз, СДЭК, Почта — фиксированная цена.
     *
     * @return array{base_price: int, ranges: array<int, array{from_amount: int, price: int}>, checkout_price: int}
     */
    private function deliverySnapshot(
        Store $store,
        ShippingMethod $shippingMethod,
        ?DeliveryInfoData $deliveryInfo,
        float $deliveryCost,
        ?YandexPriceData $yandexPrice,
    ): array {
        $checkoutPrice = (int) round($deliveryCost);

        $ladder = match ($shippingMethod) {
            ShippingMethod::Courier => $this->deliveryLadder($store, $deliveryInfo),
            ShippingMethod::YandexDelivery => DeliveryPriceLadder::fromFreeDeliveryFrom(
                $yandexPrice?->priceRounded() ?? $checkoutPrice,
                $this->yandexFreeDeliveryFrom($store, $deliveryInfo),
            ),
            default => DeliveryPriceLadder::fromRanges($checkoutPrice, []),
        };

        return DeliveryPriceSnapshot::forCheckout($ladder, $checkoutPrice)->toArray();
    }

    /**
     * Порог бесплатной доставки для Яндекса (MID-280): бесплатная ступень зоны, без неё
     * порог магазина. Платных ступеней у зоны Яндекса нет: цену называет Яндекс.
     */
    private function yandexFreeDeliveryFrom(Store $store, ?DeliveryInfoData $deliveryInfo): ?int
    {
        return $deliveryInfo?->ladder()->freeFrom() ?? $store->deliveryLadder()->freeFrom();
    }

    private function createOrderAddress(Order $order, $address): void
    {
        $order->address()->create([
            'original_id' => $address->id,
            'country' => $address->country,
            'city' => $address->city,
            'street' => $address->street,
            'house_number' => $address->house_number,
            'entrance' => $address->entrance,
            'floor' => $address->floor,
            'apartment' => $address->apartment,
            'intercom' => $address->intercom,
            'comment' => $address->comment,
            'full_address' => $address->full_address ?? $address->buildFullAddress(),
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'object_data' => $this->objectSnapshotter->snapshot($address),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, \App\Services\Pricing\PriceTierSnapshot>  $tierSnapshots  cart_item_id => снапшот лесенки (MID-308)
     */
    private function createOrderItems(Order $order, Collection $cartItems, array $tierSnapshots = []): void
    {
        // Обрабатываем только корневые позиции; дочерние (товарные добавки) —
        // отдельно через createChildOrderItems после создания root order_item.
        $roots = $cartItems->whereNull('parent_id');
        $childrenByParent = $cartItems->whereNotNull('parent_id')->groupBy('parent_id');

        foreach ($roots as $cartItem) {
            $product = $cartItem->product;
            $firstImage = $product?->images?->first();

            // measurement_unit в order_item = единица хранения quantity.
            // Quantity в корзине: для товаров с квантом (step>1) уже в граммах,
            // для kilogram-товаров без кванта (step<=1) — в кг → в граммы.
            $measurementUnit = $product?->measurement_unit ?? MeasurementUnit::Piece;
            $quantity = $this->storedQuantity($cartItem->quantity, $product);
            if ($measurementUnit === MeasurementUnit::Kilogram) {
                $measurementUnit = MeasurementUnit::Gram;
            }

            // Снапшот «без скидки на момент покупки»: фиксируется на create и не меняется,
            // чтобы последующий пересчёт бонусов не попадал под появившиеся акции.
            // Источник скидки тот же, что у корзины ({@see ItemDiscountResolver}):
            // в сетевом каталоге это снапшот скидки оффера магазина покупки.
            $eligibleForBonus = $product ? ! $this->itemDiscount->hasDiscount($cartItem) : true;

            // Цена добавок за порцию суммируется с price и множится на portions —
            // зеркало формулы из CartCalculator::calculateSubtotalWithSupplements.
            $supplementsPerPortion = $cartItem->relationLoaded('supplements')
                ? (int) $cartItem->supplements->sum(fn ($cis) => $cis->relationLoaded('values')
                    ? (int) $cis->values->sum('price')
                    : 0)
                : 0;

            $orderItem = $order->items()->create(array_merge([
                'product_id' => $cartItem->product_id,
                'title' => $product?->title ?? 'Товар',
                'sku' => $product?->sku ?? '',
                'price' => $cartItem->price,
                'price_old' => $cartItem->price_old,
                'quantity' => $quantity,
                'total' => round(($cartItem->price + $supplementsPerPortion) * $cartItem->portions, 2),
                'vat_rate' => $product?->vat_rate,
                'measurement_unit' => $measurementUnit->value,
                'image_url' => $firstImage?->path ?? null,
                'eligible_for_bonus' => $eligibleForBonus,
                'created_at' => now(),
                // Цены от количества (MID-308, финальное ревью F1): ключ price_tiers
                // добавляется в INSERT только когда снапшот есть — иначе он попадал
                // бы в запрос безусловно (и на выключенном флаге, и на форках, где
                // миграция order_items.price_tiers ещё не накатилась между деплоем
                // и migrate --force). price, price_old, total и eligible_for_bonus
                // выше уже посчитаны по ступени (requote() в execute()).
            ], isset($tierSnapshots[$cartItem->id])
                ? ['price_tiers' => $tierSnapshots[$cartItem->id]->toArray()]
                : []));

            // Копировать виртуальные добавки из CartItem в OrderItem
            $this->copySupplementsToOrderItem($orderItem, $cartItem);

            // Перенести дочерние cart_items (товарные добавки из каталога) в дочерние order_items
            $this->createChildOrderItems($order, $orderItem, $childrenByParent->get($cartItem->id, collect()));
        }
    }

    /**
     * Создать дочерние order_items из дочерних cart_items (товарные добавки из каталога).
     *
     * Каждый дочерний cart_item ссылается на реальный Product (соус, топпинг и т.п.),
     * поэтому фиксируем снапшот price/title/sku/measurement_unit из продукта на момент оформления.
     */
    private function createChildOrderItems(Order $order, OrderItem $parentItem, Collection $children): void
    {
        foreach ($children as $child) {
            $product = $child->product;
            $measurementUnit = $product?->measurement_unit ?? MeasurementUnit::Piece;
            $quantity = $this->storedQuantity($child->quantity, $product);
            if ($measurementUnit === MeasurementUnit::Kilogram) {
                $measurementUnit = MeasurementUnit::Gram;
            }

            $order->items()->create([
                'parent_id' => $parentItem->id,
                'source_supplement_value_id' => $child->source_supplement_value_id,
                'product_id' => $child->product_id,
                'title' => $product?->title ?? 'Добавка',
                'sku' => $product?->sku ?? '',
                'price' => $child->price,
                'price_old' => $child->price_old,
                'quantity' => $quantity,
                'total' => round((float) $child->price * $child->portions, 2),
                'vat_rate' => $product?->vat_rate,
                'measurement_unit' => $measurementUnit->value,
                'image_url' => $product?->images?->first()?->path,
                'eligible_for_bonus' => false,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Quantity позиции корзины в единице хранения позиции заказа (граммы для
     * весовых). Конвертируем кг→г ТОЛЬКО у kilogram-товара без кванта: при
     * шаге продажи (step>1) корзина хранит граммы и так — та же ветка, что в
     * {@see \App\Models\CartItem::grams()}, {@see \App\Services\Delivery\CartProductsMapper::portions()}
     * и {@see \App\RabbitMQ\Transformers\OrderEventTransformer} при сборке items.
     *
     * Без проверки шага рассинхрон карточки (единица kilogram при шаге 500 г —
     * такую присылает 1С, не знающий про квант) раздувал вес позиции в 1000 раз:
     * прод-заказ 204 от 10.09.2026, 1 кг огурцов → 1000 кг и 865 995 ₽.
     */
    private function storedQuantity(int $cartQuantity, ?Product $product): int
    {
        $step = (int) ($product?->quantity_step ?? 1);

        if ($step <= 1 && $product?->measurement_unit === MeasurementUnit::Kilogram) {
            return $cartQuantity * 1000;
        }

        return $cartQuantity;
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

            // Скопировать values с snapshot цены
            if ($cartItemSupplement->relationLoaded('values')) {
                foreach ($cartItemSupplement->values as $value) {
                    $orderItemSupplement->values()->attach($value->id, [
                        'amount' => $value->price,
                    ]);
                }
            }
        }
    }

    private function createOrderPromocode(Order $order, $cartPromocode, float $discountAmount): void
    {
        $promocode = $cartPromocode->promocode;

        $order->promocode()->create([
            'original_id' => $promocode->id,
            'code' => $promocode->code,
            'type' => $promocode->type,
            'value' => $promocode->value,
            'discount_amount' => $discountAmount,
            'created_at' => now(),
        ]);
    }
}
