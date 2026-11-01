<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\DTOs\Order\CreateClientOrderData;
use App\DTOs\Order\CreateOrderData;
use App\Enums\OrderStatus;
use App\Enums\ShippingMethod;
use App\Exceptions\EmptyCartException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentCard;
use App\Models\Promocode;
use App\Models\PromocodeUsage;
use App\Services\Order\ClientOrderStatusAggregator;
use App\Services\Payment\PaymentService;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Действие создания marketplace client-order'а.
 *
 * Поведение:
 * - Получает корзину пользователя, группирует по store_id
 * - Для каждого store вызывает {@see CreateOrderAction} с paymentType='cash' — это намеренный
 *   bypass per-order hold в CreateOrderAction (строки 368-377), чтобы оплата картой была
 *   консолидирована на уровне client_order через {@see PaymentService::createConsolidatedHold}
 * - После создания sub-orders проставляет client_order_id на все в той же DB::transaction
 * - Если $data->paymentType === 'card' — вне транзакции вызывает createConsolidatedHold
 *   на суммарный totalAmount, возвращает реальный confirmationUrl
 * - Возвращает aggregate: clientOrderId, subOrderIds, totalAmount, consolidatedStatus, confirmationUrl
 *
 * Bonus aggregation: bonus_spent/earned записываются per sub-order в {@see CreateOrderAction},
 * но агрегированная BonusTransaction с client_order_id создаётся через
 * {@see \App\RabbitMQ\Transformers\OrderEventTransformer::handleDeliveredSubOrder} (earn)
 * или {@see CancelClientOrderAction} (refund). См. M.14 в marketplace-model.md.
 *
 * Platform-wide промокоды: применяются через {@see \App\Actions\Cart\ApplyPromocodeAction}
 * с scope='platform', автоматически учитываются в eligible amount расчёта корзины.
 * Фиксация использования (used_count + promocode_usages) — см.
 * {@see self::trackPlatformWidePromocodeUsage} внутри той же DB::transaction,
 * чтобы откатывалось вместе с sub-orders при падении.
 */
final class CreateClientOrderAction
{
    public function __construct(
        private readonly CreateOrderAction $createOrderAction,
        private readonly PaymentService $paymentService,
        private readonly ModuleConfigService $moduleConfigService,
        private readonly SlotsApiClientInterface $slotsApiClient,
        private readonly \App\Services\Discount\ItemDiscountResolver $itemDiscount,
    ) {
    }

    /**
     * @return array{
     *     clientOrderId: string,
     *     subOrderIds: array<int, int>,
     *     totalAmount: int,
     *     consolidatedStatus: string,
     *     confirmationUrl: ?string,
     * }
     *
     * @throws EmptyCartException
     */
    public function execute(CreateClientOrderData $data): array
    {
        // Идемпотентность: sub-orders создаются с композитными ключами вида "key:0", "key:1".
        // Ищем первый из них — если есть, клиентский заказ уже был создан ранее.
        if ($data->idempotencyKey) {
            $existing = Order::query()
                ->where('user_id', $data->user->id)
                ->where('idempotency_key', $data->idempotencyKey.':0')
                ->whereNotNull('client_order_id')
                ->first();

            if ($existing) {
                return $this->aggregateExisting((string) $existing->client_order_id, $data->user->id);
            }
        }

        $cart = Cart::query()
            ->where('user_id', $data->user->id)
            ->with('items')
            ->first();

        if (! $cart) {
            throw new EmptyCartException;
        }

        $isPickupPoint = $data->shippingMethod->isPickupPoint();

        // Оформление без слотов (модуль checkout_without_slots, MID-256):
        // deliverySlots не переданы → по каждому магазину корзины создаётся
        // заказ «на ближайшее время» (slotId=null). Переданные слоты обрабатываются
        // как раньше (с проверкой покрытия магазинов). Самовывоз в модуль не
        // входит — время выдачи в магазине выбирает клиент.
        $withoutSlots = ! $isPickupPoint
            && $data->shippingMethod !== ShippingMethod::Pickup
            && $data->deliverySlots === []
            && $this->moduleConfigService->isCheckoutWithoutSlotsEnabled();

        if (! $isPickupPoint && ! $withoutSlots && $data->deliverySlots === []) {
            throw new EmptyCartException;
        }

        if ($cart->items->isEmpty()) {
            throw new EmptyCartException;
        }

        $address = Address::findOrFail($data->addressId);

        // Сценарии:
        // - Courier: groups = deliverySlots[] (фронт сам передал {storeId, slotId} по магазинам).
        // - Pickup: groups = deliverySlots[] (тот же контракт), но добавляется
        //   проверка pickup_payment_methods по РЕАЛЬНОМУ paymentType client-order'а.
        // - Cdek/RussianPost: groups собираем сами из уникальных store_id корзины,
        //   slotId=null. Pre-flight гарантирует что все магазины поддерживают метод.
        if ($isPickupPoint) {
            $groups = $this->resolvePickupPointGroups($cart, $data);
        } elseif ($withoutSlots) {
            $groups = $this->resolveWithoutSlotGroups($cart);
        } else {
            $groups = $data->deliverySlots;
            $this->validateSlotStoreCoverage($cart, $groups);
        }

        if ($data->shippingMethod === ShippingMethod::Pickup) {
            $this->validateMarketplacePickupPayment($data, $groups);
        }

        // Бонусы несовместимы с платформенным промокодом: sub-order'ы видят только
        // store-scoped промокоды (store_id=null для CreateOrderAction невидим),
        // поэтому связка «promo + бонусы» дала бы расхождение корзина ↔ заказ.
        if ($data->bonusAction !== null
            && $cart->promocodes()->whereNull('store_id')->exists()
        ) {
            throw ValidationException::withMessages([
                'bonusAction' => ['Бонусы нельзя использовать вместе с промокодом.'],
            ]);
        }

        $bonusPlan = $this->planBonusDistribution($cart, $data, $groups);

        $clientOrderId = (string) Str::uuid();
        $createdOrderIds = [];

        DB::transaction(function () use ($cart, $data, $address, $groups, $isPickupPoint, $clientOrderId, $bonusPlan, &$createdOrderIds): void {
            foreach ($groups as $index => $group) {
                $subIdempotencyKey = $data->idempotencyKey !== null
                    ? $data->idempotencyKey.':'.$index
                    : null;

                $storeId = (int) $group['storeId'];
                $storeBonus = $bonusPlan[$storeId] ?? ['action' => null, 'amount' => null];

                $subData = new CreateOrderData(
                    user: $data->user,
                    storeId: $storeId,
                    address: $address,
                    deliverySlotId: ($isPickupPoint || $group['slotId'] === null) ? null : (int) $group['slotId'],
                    shippingMethod: $data->shippingMethod,
                    comment: $data->comment,
                    idempotencyKey: $subIdempotencyKey,
                    bonusAction: $storeBonus['action'],
                    bonusSpendAmount: $storeBonus['amount'],
                    // В sub-CreateOrderData всегда 'cash' — per-order YooKassa hold
                    // в CreateOrderAction пропускается (строки 368-377). Реальный
                    // paymentType client-order'а хранится только на уровне
                    // CreateClientOrderData и используется ниже для consolidated hold.
                    paymentType: 'cash',
                    paymentCardId: null,
                    shippingTariffCode: $isPickupPoint ? $data->tariffCodeFor($storeId) : null,
                    // pickup_payment_methods проверяется на верхнем уровне
                    // (validateMarketplacePickupPayment) по реальному paymentType;
                    // здесь фиктивный 'cash' иначе валит магазин с allowed=['card'].
                    skipPickupPaymentCheck: true,
                );

                $result = $this->createOrderAction->execute($subData);
                $createdOrderIds[] = (int) $result['orderId'];
            }

            // Проставить client_order_id на все созданные sub-orders в той же транзакции
            Order::query()
                ->whereIn('id', $createdOrderIds)
                ->update(['client_order_id' => $clientOrderId]);

            // Platform-wide промокод: фиксируем использование один раз на весь client-order.
            // CreateOrderAction работает per-store и фильтрует cart_promocodes по store_id,
            // поэтому platform-wide (store_id=null) для него невидим. Трекаем здесь,
            // привязывая к первому созданному sub-order.
            $this->trackPlatformWidePromocodeUsage($cart, $data->user->id, $createdOrderIds);
        });

        // Consolidated YooKassa hold (M.9=a) — только после успешного коммита транзакции.
        // Диспатч вне транзакции чтобы gateway не видел незакоммиченные данные
        // (классическая ловушка — см. rabbitmq-pitfalls.md #2).
        $confirmationUrl = null;
        if ($data->paymentType === 'card') {
            $confirmationUrl = $this->createConsolidatedHold($data, $clientOrderId, $createdOrderIds);
        }

        return $this->aggregate($clientOrderId, $data->user->id, $createdOrderIds, $confirmationUrl);
    }

    /**
     * Скрытый маркетплейс: клиент не знает состав магазинов корзины, поэтому
     * несовпадение deliverySlots с корзиной обязано падать 422 до транзакции.
     * Без этой проверки CreateOrderAction создал бы sub-orders только по
     * переданным storeId (он берёт и списывает items своего магазина), а товары
     * непокрытых магазинов молча остались бы в корзине — тихий частичный заказ.
     *
     * @param  array<int, array{storeId: int, slotId: int}>  $groups
     */
    private function validateSlotStoreCoverage(Cart $cart, array $groups): void
    {
        $slotStoreIds = array_map(
            static fn (array $group): int => (int) $group['storeId'],
            $groups,
        );

        if (count($slotStoreIds) !== count(array_unique($slotStoreIds))) {
            throw ValidationException::withMessages([
                'deliverySlots' => ['Слоты доставки продублированы для одного магазина.'],
            ]);
        }

        $cartStoreIds = $cart->items
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();

        if ($cartStoreIds->diff($slotStoreIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'deliverySlots' => ['Выберите слоты доставки для всех товаров корзины.'],
            ]);
        }

        if (collect($slotStoreIds)->diff($cartStoreIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'deliverySlots' => ['Слоты доставки указаны для магазинов, которых нет в корзине.'],
            ]);
        }
    }

    /**
     * Для marketplace pickup-сабмита проверяем что у каждого магазина
     * допустимый paymentType юзера — по верхнему `$data->paymentType`,
     * а не по фиктивному `cash` который попадёт в sub-CreateOrderData.
     *
     * Падает 422 до открытия транзакции.
     *
     * @param  array<int, array{storeId: int}>  $groups
     */
    private function validateMarketplacePickupPayment(CreateClientOrderData $data, array $groups): void
    {
        $storeIds = collect($groups)->pluck('storeId')->map(fn ($v) => (int) $v)->unique()->all();
        if ($storeIds === []) {
            return;
        }

        $stores = \App\Models\Store::query()
            ->whereIn('id', $storeIds)
            ->get(['id', 'pickup_payment_methods', 'is_pickup_enabled']);

        foreach ($stores as $store) {
            if (! $store->is_pickup_enabled) {
                throw ValidationException::withMessages([
                    'shippingMethod' => ['Самовывоз недоступен для части товаров корзины.'],
                ]);
            }

            $allowed = is_array($store->pickup_payment_methods) && $store->pickup_payment_methods !== []
                ? $store->pickup_payment_methods
                : ['card'];

            if (! in_array($data->paymentType, $allowed, true)) {
                throw ValidationException::withMessages([
                    'paymentType' => ['Выбранный способ оплаты недоступен для самовывоза части товаров корзины.'],
                ]);
            }
        }
    }

    /**
     * Собирает группы (storeId-based) для cdek/russian_post сценария.
     *
     * Шаги:
     * 1. Выясняет уникальные store_id из cart_items.
     * 2. Проверяет что выбранный shippingMethod включён в project_modules.
     * 3. Проверяет что у каждого store есть активная integration_configs.
     * 4. Проверяет что для каждого store есть код тарифа (через CreateClientOrderData::tariffCodeFor).
     *
     * Падает 422 ВНЕ DB::transaction, чтобы не было частичных списаний/holds.
     *
     * @return array<int, array{storeId: int}>
     */
    /**
     * Группы для оформления без слотов: один заказ на каждый магазин корзины,
     * slotId=null (слот в заказе не пишется, доставка «в ближайшее время»).
     *
     * @return array<int, array{storeId: int, slotId: null}>
     */
    private function resolveWithoutSlotGroups(Cart $cart): array
    {
        $storeIds = $cart->items
            ->pluck('store_id')
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            throw new EmptyCartException;
        }

        return $storeIds
            ->map(fn (int $storeId) => ['storeId' => $storeId, 'slotId' => null])
            ->all();
    }

    private function resolvePickupPointGroups(Cart $cart, CreateClientOrderData $data): array
    {
        $storeIds = $cart->items
            ->pluck('store_id')
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            throw new EmptyCartException;
        }

        // Глобальный feature-flag модуля доставки.
        $moduleEnabled = match ($data->shippingMethod) {
            ShippingMethod::Cdek => $this->moduleConfigService->isCdekEnabled(),
            ShippingMethod::RussianPost => $this->moduleConfigService->isRussianPostEnabled(),
            default => false,
        };

        if (! $moduleEnabled) {
            throw ValidationException::withMessages([
                'shippingMethod' => ['Выбранный способ доставки сейчас недоступен.'],
            ]);
        }

        $integrationType = match ($data->shippingMethod) {
            ShippingMethod::Cdek => 'delivery_cdek',
            ShippingMethod::RussianPost => 'delivery_russian_post',
            default => throw new \LogicException('resolvePickupPointGroups вызван для метода без интеграции'),
        };

        $missingIntegration = [];
        $missingTariff = [];

        foreach ($storeIds as $storeId) {
            $configs = $this->slotsApiClient->getShopIntegrationConfigs($storeId, [$integrationType]);

            $isActive = collect($configs)
                ->where('integration_type', $integrationType)
                ->first()['is_active'] ?? false;

            if (! $isActive) {
                $missingIntegration[] = $storeId;
            }

            if ($data->tariffCodeFor($storeId) === null) {
                $missingTariff[] = $storeId;
            }
        }

        if ($missingIntegration !== []) {
            // Скрытый маркетплейс — не раскрываем конкретные store_id в сообщении пользователю,
            // но логируем для диагностики на стороне бэка.
            throw ValidationException::withMessages([
                'shippingMethod' => [
                    'Часть товаров корзины недоступна для выбранного способа доставки. Уберите их или выберите другой способ.',
                ],
            ]);
        }

        if ($missingTariff !== []) {
            throw ValidationException::withMessages([
                'shippingTariffCodes' => ['Не указан тариф доставки для всех товаров корзины.'],
            ]);
        }

        return $storeIds
            ->map(fn (int $storeId): array => ['storeId' => $storeId])
            ->values()
            ->all();
    }

    /**
     * Фиксация использования platform-wide промокода — ровно один раз на client-order.
     *
     * Attach'ит запись в promocode_usages (user_id + order_id первого sub-order),
     * инкрементит used_count промокода и удаляет cart_promocode (store_id=null).
     * Выполняется внутри DB::transaction родительского execute(), чтобы откатилось
     * вместе с sub-orders при падении.
     *
     * @param  array<int, int>  $createdOrderIds
     */
    private function trackPlatformWidePromocodeUsage(Cart $cart, int $userId, array $createdOrderIds): void
    {
        if ($createdOrderIds === []) {
            return;
        }

        $platformCartPromocode = $cart->promocodes()
            ->whereNull('store_id')
            ->with('promocode')
            ->first();

        if ($platformCartPromocode === null || $platformCartPromocode->promocode === null) {
            return;
        }

        /** @var Promocode $promocode */
        $promocode = $platformCartPromocode->promocode;

        if (! $promocode->isPlatformWide()) {
            return;
        }

        PromocodeUsage::create([
            'promocode_id' => $promocode->id,
            'user_id' => $userId,
            'order_id' => $createdOrderIds[0],
            'used_at' => now(),
        ]);

        Promocode::where('id', $promocode->id)->increment('used_count');

        // Platform-wide cart_promocode живёт в cart_promocodes с store_id=null.
        // CreateOrderAction удаляет только per-store записи — убираем platform-wide здесь.
        $cart->promocodes()->whereNull('store_id')->delete();
    }

    /**
     * @param  array<int, int>  $createdOrderIds
     */
    private function createConsolidatedHold(
        CreateClientOrderData $data,
        string $clientOrderId,
        array $createdOrderIds,
    ): ?string {
        $orders = Order::query()
            ->whereIn('id', $createdOrderIds)
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $totalAmount = (float) $orders->sum('total');

        $paymentMethodId = null;
        if ($data->paymentCardId !== null) {
            $paymentMethodId = PaymentCard::find($data->paymentCardId)?->payment_method_id;
        }

        $result = $this->paymentService->createConsolidatedHold(
            orders: $orders->all(),
            totalAmount: $totalAmount,
            clientOrderId: $clientOrderId,
            paymentMethodId: $paymentMethodId,
            returnUrl: $data->returnUrl,
        );

        return $result->confirmationUrl;
    }

    /**
     * @return array{
     *     clientOrderId: string,
     *     subOrderIds: array<int, int>,
     *     totalAmount: int,
     *     consolidatedStatus: string,
     *     confirmationUrl: ?string,
     * }
     */
    private function aggregateExisting(string $clientOrderId, int $userId): array
    {
        $ids = Order::query()
            ->where('user_id', $userId)
            ->where('client_order_id', $clientOrderId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->aggregate($clientOrderId, $userId, $ids, null);
    }

    /**
     * @param  array<int, int>  $subOrderIds
     * @return array{
     *     clientOrderId: string,
     *     subOrderIds: array<int, int>,
     *     totalAmount: int,
     *     consolidatedStatus: string,
     *     confirmationUrl: ?string,
     * }
     */
    private function aggregate(string $clientOrderId, int $userId, array $subOrderIds, ?string $confirmationUrl): array
    {
        $orders = Order::query()
            ->where('user_id', $userId)
            ->where('client_order_id', $clientOrderId)
            ->get();

        $totalAmount = round((float) $orders->sum('total'), 2);
        $consolidatedStatus = self::consolidateStatuses(
            $orders->map(fn (Order $o) => $o->status instanceof OrderStatus ? $o->status : OrderStatus::from((string) $o->status))->all()
        );

        return [
            'clientOrderId' => $clientOrderId,
            'subOrderIds' => array_values($subOrderIds),
            'totalAmount' => $totalAmount,
            'consolidatedStatus' => $consolidatedStatus->value,
            'confirmationUrl' => $confirmationUrl,
        ];
    }

    /**
     * Консолидация статусов по правилу "минимальный прогресс".
     *
     * Делегирует в {@see ClientOrderStatusAggregator} (инвариант I-3: refactor-rename без
     * изменения поведения). Метод сохранён как статический фасад для сохранения
     * обратной совместимости call-site'ов.
     *
     * @param  array<int, OrderStatus>  $statuses
     */
    public static function consolidateStatuses(array $statuses): OrderStatus
    {
        return (new ClientOrderStatusAggregator())->aggregate($statuses);
    }

    /**
     * Распределение бонусов клиентского заказа по sub-order'ам (map storeId → план).
     *
     * earn: каждому магазину с is_bonus_enabled — bonusAction='earn'
     *   (CreateOrderAction::previewBonus сам вернёт null при нулевом eligible).
     * spend: greedy в порядке $groups — spend_i = min(remaining, квота_i),
     *   квота_i = floor(eligible_i × bonus_spend_percent_i / 100),
     *   eligible_i — товары магазина без скидки (зеркало previewBonus).
     *   Магазины без положительной доли получают action=null — иначе
     *   previewBonus упал бы 422 bonus_spend_failed на весь client-order.
     *   Магазины со store-scoped промокодом пропускаются (eligible там 0).
     *
     * @param  array<int, array{storeId: int}>  $groups
     * @return array<int, array{action: ?string, amount: ?int}>
     */
    private function planBonusDistribution(Cart $cart, CreateClientOrderData $data, array $groups): array
    {
        $plan = [];
        foreach ($groups as $group) {
            $plan[(int) $group['storeId']] = ['action' => null, 'amount' => null];
        }

        if ($data->bonusAction === null) {
            return $plan;
        }

        $storeIds = array_keys($plan);
        $stores = \App\Models\Store::query()
            ->whereIn('id', $storeIds)
            ->get()
            ->keyBy('id');

        if ($data->bonusAction === 'earn') {
            foreach ($storeIds as $storeId) {
                if ((bool) ($stores[$storeId]?->is_bonus_enabled ?? false)) {
                    $plan[$storeId]['action'] = 'earn';
                }
            }

            return $plan;
        }

        // spend
        $cart->items->loadMissing('product');
        $itemsByStore = $cart->items->groupBy('store_id');
        $storesWithOwnPromocode = $cart->promocodes()
            ->whereNotNull('store_id')
            ->pluck('store_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $remaining = min(
            (int) ($data->bonusSpendAmount ?? 0),
            (int) $data->user->available_bonus_balance,
        );

        foreach ($storeIds as $storeId) {
            if ($remaining <= 0) {
                break;
            }

            $store = $stores[$storeId] ?? null;
            if ($store === null
                || ! $store->is_bonus_enabled
                || in_array($storeId, $storesWithOwnPromocode, true)
            ) {
                continue;
            }

            $eligible = (int) ($itemsByStore->get($storeId) ?? collect())
                ->filter(fn ($item) => $item->product && ! $this->itemDiscount->hasDiscount($item))
                ->sum(fn ($item) => $item->price * $item->portions);

            $spend = min($remaining, (int) floor($eligible * (int) ($store->bonus_spend_percent ?? 0) / 100));

            if ($spend <= 0) {
                continue;
            }

            $plan[$storeId] = ['action' => 'spend', 'amount' => $spend];
            $remaining -= $spend;
        }

        $distributed = array_sum(array_map(fn (array $p) => (int) ($p['amount'] ?? 0), $plan));
        if ($distributed <= 0) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'bonus_spend_failed',
                    'message' => 'Невозможно списать бонусы',
                ], 422),
            );
        }

        return $plan;
    }
}
