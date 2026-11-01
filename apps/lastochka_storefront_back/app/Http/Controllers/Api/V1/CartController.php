<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Cart\ApplyPromocodeAction;
use App\Actions\Cart\BatchAddToCartAction;
use App\Actions\Cart\ClearCartAction;
use App\Actions\Cart\ClearStoreGroupAction;
use App\Actions\Cart\RemoveCartItemAction;
use App\Actions\Cart\RemovePromocodeAction;
use App\Actions\Cart\SwitchCartStoreAction;
use App\Actions\Cart\UpdateCartItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cart\AddToCartRequest;
use App\Http\Requests\Api\V1\Cart\ApplyPromocodeRequest;
use App\Http\Requests\Api\V1\Cart\BatchAddToCartRequest;
use App\Http\Requests\Api\V1\Cart\RemovePromocodeRequest;
use App\Http\Requests\Api\V1\Cart\SwitchCartStoreRequest;
use App\Http\Requests\Api\V1\Cart\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\CartResource;
use App\Http\Resources\Api\V1\ClientCartResource;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Cart\AvailableShippingMethodsResolver;
use App\Services\Cart\CartStoreOffers;
use App\Services\Delivery\DeliveryService;
use App\Services\Pricing\ProductPriceResolver;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Cart', weight: 5)]
final class CartController extends Controller
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfigService,
        private readonly CartStoreOffers $storeOffers,
        private readonly ProductPriceResolver $priceResolver,
    ) {
    }

    /**
     * Получить корзину
     *
     * Возвращает текущую корзину пользователя. Ответ отдаётся без обёртки `data`.
     * Перед сериализацией синхронизирует цены позиций (price и снапшот price_old)
     * и удаляет недоступные товары.
     *
     * Цены доставки считаются для переданного addressId, а при его отсутствии —
     * для активного адреса пользователя; адрес без координат приравнивается к
     * отсутствию адреса.
     *
     * Возвращает CartResource: storeGroups[], weightTotal, isHeavy,
     * availableShippingMethods.
     *
     * Если записи корзины нет — отдаётся пустая форма без availableShippingMethods:
     * {storeGroups: [], weightTotal: null, isHeavy: false}.
     *
     * @response array{storeGroups: array, weightTotal: float|null, isHeavy: bool, availableShippingMethods: array<array{method: string, label: string, available: bool, reason: string|null}>}
     */
    #[QueryParameter('addressId', description: 'ID адреса для получения актуальных цен доставки', type: 'int', example: 1)]
    #[QueryParameter('shippingMethod', description: 'Способ доставки для расчёта цены: courier (по умолчанию), pickup, коды пунктов выдачи внешних служб', type: 'string', default: 'courier', example: 'pickup')]
    #[\Dedoc\Scramble\Attributes\Response(403, description: 'Адрес принадлежит другому пользователю', type: 'array{error: string, message: string}')]
    #[\Dedoc\Scramble\Attributes\Response(404, description: 'Адрес не найден', type: 'array{error: string, message: string}')]
    public function index(Request $request): CartResource|ClientCartResource|JsonResponse
    {
        $addressId = $request->integer('addressId');
        $address = null;

        if ($addressId > 0) {
            $address = Address::find($addressId);

            if ($address === null) {
                return response()->json([
                    'error' => 'address_not_found',
                    'message' => 'Адрес не найден',
                ], 404);
            }

            if ($address->user_id !== $request->user()->id) {
                return response()->json([
                    'error' => 'forbidden',
                    'message' => 'Доступ запрещён',
                ], 403);
            }
        } else {
            // Автоматически берём активный адрес пользователя
            $address = Address::query()
                ->where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->first();
        }

        // Сбрасываем адрес если нет координат
        if ($address && ($address->latitude === null || $address->longitude === null)) {
            $address = null;
        }

        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with(Cart::RESPONSE_RELATIONS)
            ->first();

        if (! $cart) {
            return $this->emptyCartResponse();
        }

        // Сетевой каталог: цена/остаток позиции живут в оффере её магазина
        // покупки, а не в колонках глобальной карточки — навешиваем офферы
        // до пересчёта и до чистки недоступных позиций.
        $this->storeOffers->attachToCart($cart);

        // Цены от количества (MID-308): лесенки всех позиций одним запросом.
        $this->priceResolver->preloadCartItems($cart->items);

        $this->syncPrices($cart);
        $this->removeUnavailableItems($cart);

        $freshCart = $cart->fresh(Cart::RESPONSE_RELATIONS);
        $this->storeOffers->attachToCart($freshCart);

        if ($this->moduleConfigService->isMarketplaceMode()) {
            return (new ClientCartResource($freshCart))->withAddress($address);
        }

        return (new CartResource($freshCart))->withAddress($address);
    }

    /**
     * Агрегированная сводка доставки для всей корзины (только marketplace режим).
     *
     * Маршрут закрыт middleware `marketplace.mode`: в стандартном режиме отдаёт 404.
     * Адрес берётся из addressId, а при его отсутствии — из активного адреса
     * пользователя с координатами. Доставка запрашивается на завтрашнюю дату;
     * магазины, по которым внешний сервис недоступен, пропускаются.
     *
     * Возвращает:
     * - totalShippingPrice: сумма стоимостей доставки от всех магазинов; 0, если
     *   сумма корзины достигла marketplace-порога бесплатной доставки
     * - freeShippingFrom: marketplace-wide порог, null — если не сконфигурирован
     * - estimatedDates: уникальные даты первого слота каждого магазина, по возрастанию
     * - isMultiDate: есть ли несколько дат
     * - dateGroups: всегда пустой массив
     * - availableShippingMethods: доступность способов доставки для текущей корзины
     *
     * @response array{totalShippingPrice: float, freeShippingFrom: float|null, estimatedDates: string[], isMultiDate: bool, dateGroups: array, availableShippingMethods: array<array{method: string, label: string, available: bool, reason: string|null}>}
     */
    #[QueryParameter('addressId', description: 'ID адреса для получения актуальных цен доставки', type: 'int', example: 1)]
    #[\Dedoc\Scramble\Attributes\Response(404, description: 'Адрес не найден или принадлежит другому пользователю', type: 'array{error: string}')]
    public function deliverySummary(Request $request, DeliveryService $deliveryService): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $addressId = $request->integer('addressId');
        $address = null;

        if ($addressId > 0) {
            $address = Address::find($addressId);
            if ($address === null || $address->user_id !== $userId) {
                return response()->json(['error' => 'address_not_found'], 404);
            }
        } else {
            $address = Address::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->first();
        }

        $cart = Cart::query()
            ->where('user_id', $userId)
            ->with(['items.store'])
            ->first();

        $totalShippingPrice = 0.0;
        $estimatedDatesSet = [];

        if ($cart && $cart->items && $cart->items->isNotEmpty()) {
            $itemsByStore = $cart->items->groupBy('store_id');
            foreach ($itemsByStore as $storeItems) {
                $store = $storeItems->first()->store ?? null;
                if ($store === null || ! $address) {
                    continue;
                }

                try {
                    $storeItems->loadMissing('product');
                    $info = $deliveryService->getDeliveryInfo(
                        $store,
                        $address,
                        now()->addDay()->format('Y-m-d'),
                        app(\App\Services\Delivery\CartProductsMapper::class)->map($storeItems),
                    );
                    // Цена доставки магазина — тем же расчётом, что в корзине:
                    // ступени зоны и порог, а не базовая цена зоны
                    $totalShippingPrice += app(\App\Services\Cart\StoreDeliveryCostResolver::class)
                        ->resolve($store, $storeItems, $address);
                    if (! empty($info->slots)) {
                        $firstSlot = $info->slots[0] ?? null;
                        if ($firstSlot && isset($firstSlot->startAt) && $firstSlot->startAt instanceof \DateTimeInterface) {
                            $estimatedDatesSet[$firstSlot->startAt->format('Y-m-d')] = true;
                        }
                    }
                } catch (\Throwable $e) {
                    // Graceful degradation — игнорируем недоступные магазины в aggregate
                }
            }
        }

        $marketplaceFreeShippingThreshold = $this->moduleConfigService->getMarketplaceFreeShippingThreshold();
        $totalAmount = $cart && $cart->items
            ? (int) $cart->items->sum(fn ($item) => (int) $item->price * (int) ($item->portions ?? $item->quantity))
            : 0;

        if ($marketplaceFreeShippingThreshold !== null && $totalAmount >= $marketplaceFreeShippingThreshold) {
            $totalShippingPrice = 0.0;
        }

        $estimatedDates = array_values(array_keys($estimatedDatesSet));
        sort($estimatedDates);

        return response()->json([
            'totalShippingPrice' => $totalShippingPrice,
            'freeShippingFrom' => $marketplaceFreeShippingThreshold,
            'estimatedDates' => $estimatedDates,
            'isMultiDate' => count($estimatedDates) > 1,
            'dateGroups' => [],
            'deliveryGroups' => ClientCartResource::deliveryGroupsFor($cart?->items ?? collect()),
            'availableShippingMethods' => app(AvailableShippingMethodsResolver::class)->resolve($cart),
        ]);
    }

    /**
     * Массовое добавление товаров в корзину
     *
     * Добавляет несколько товаров в корзину одним запросом (максимум 100 позиций).
     * Товары, которые не удалось добавить, возвращаются в skippedProducts с причиной:
     * not_found (товара нет в БД), out_of_stock (недостаточно остатка),
     * store_access_denied (магазин недоступен пользователю), unavailable (любая другая ошибка
     * добавления — неактивный товар, вариативность, некратное количество).
     * Для not_found поле title — пустая строка.
     *
     * Статус 422 отдаётся, когда added = 0 и skipped > 0; в остальных случаях 200.
     *
     * @response array{added: int, skipped: int, skippedProducts: array<array{id: int, title: string, reason: string}>}
     */
    #[\Dedoc\Scramble\Attributes\HeaderParameter('Idempotency-Key', 'Ключ идемпотентности (до 128 символов), новый на каждое действие пользователя. Повтор с тем же ключом в течение суток не меняет корзину и возвращает сохранённый ответ первого успешного запроса с заголовком Idempotency-Replayed: true. Тот же ключ с другим телом: 422 IDEMPOTENCY_KEY_REUSED; дубль, пока первый запрос ещё выполняется: 409 IDEMPOTENCY_REQUEST_IN_PROGRESS.', required: false, type: 'string')]
    public function batchAddItems(BatchAddToCartRequest $request, BatchAddToCartAction $action): JsonResponse
    {
        $storeIdRaw = $request->validated('storeId');

        $result = $action->execute(
            $request->user(),
            $request->validated('items'),
            $storeIdRaw === null ? null : (int) $storeIdRaw,
        );

        $status = $result['added'] === 0 && $result['skipped'] > 0 ? 422 : 200;

        return response()->json($result, $status);
    }

    /**
     * Добавить товар в корзину
     *
     * Добавляет товар в корзину. Если в корзине уже есть корневая позиция того же
     * товара с идентичным набором добавок — количество суммируется, цены и снапшот
     * price_old обновляются, количества дочерних позиций (товарных добавок)
     * пересчитываются пропорционально порциям. Иначе создаётся новая позиция.
     *
     * Возвращает корзину целиком.
     */
    #[\Dedoc\Scramble\Attributes\HeaderParameter('Idempotency-Key', 'Ключ идемпотентности (до 128 символов), новый на каждое действие пользователя. Повтор с тем же ключом в течение суток не меняет корзину и возвращает сохранённый ответ первого успешного запроса с заголовком Idempotency-Replayed: true. Тот же ключ с другим телом: 422 IDEMPOTENCY_KEY_REUSED; дубль, пока первый запрос ещё выполняется: 409 IDEMPOTENCY_REQUEST_IN_PROGRESS.', required: false, type: 'string')]
    public function addItem(AddToCartRequest $request, AddToCartAction $action): CartResource|ClientCartResource
    {
        $storeIdRaw = $request->validated('storeId');

        $cart = $action->execute(
            $request->user(),
            $request->validated('productId'),
            $request->validated('quantity'),
            $request->validated('supplements'),
            $request->validated('deliveryDate'),
            $storeIdRaw === null ? null : (int) $storeIdRaw,
        );

        return $this->cartWithAddress($cart, $request->user());
    }

    /**
     * Изменить количество товара
     *
     * Обновляет количество указанной позиции корзины. Переданный supplements
     * пересобирает набор добавок целиком (пустой массив снимает все добавки);
     * если поле не передано, дочерние позиции пересчитываются пропорционально
     * изменению порций. deliveryDate обновляется только непустым значением.
     */
    #[\Dedoc\Scramble\Attributes\Response(404, description: 'Позиция не найдена: {"error": "CART_ITEM_NOT_FOUND", "message": "Позиция уже удалена из корзины"}')]
    public function updateItem(
        UpdateCartItemRequest $request,
        CartItem $cartItem,
        UpdateCartItemAction $action,
    ): CartResource|ClientCartResource {
        $this->authorize('update', $cartItem);

        $cart = $action->execute(
            $cartItem,
            $request->validated('quantity'),
            $request->validated('supplements'),
            $request->validated('deliveryDate'),
        );

        return $this->cartWithAddress($cart, $request->user());
    }

    /**
     * Удалить товар из корзины
     *
     * Удаляет указанную позицию корзины; дочерние позиции (товарные добавки)
     * удаляются каскадом. Если позиций этого магазина не осталось, снимается
     * store-scoped промокод магазина; platform-wide промокод не затрагивается.
     */
    #[\Dedoc\Scramble\Attributes\Response(404, description: 'Позиция не найдена: {"error": "CART_ITEM_NOT_FOUND", "message": "Позиция уже удалена из корзины"}')]
    public function removeItem(Request $request, CartItem $cartItem, RemoveCartItemAction $action): CartResource|ClientCartResource
    {
        $this->authorize('delete', $cartItem);

        $cart = $action->execute($cartItem);

        return $this->cartWithAddress($cart, $request->user());
    }

    /**
     * Очистить корзину
     *
     * Удаляет все позиции корзины и все применённые промокоды (store-scoped и
     * platform-wide). Отсутствие корзины ошибкой не считается. Тело ответа пустое.
     */
    #[\Dedoc\Scramble\Attributes\Response(204, description: 'Корзина очищена')]
    public function clear(Request $request, ClearCartAction $action): \Illuminate\Http\Response
    {
        $action->execute($request->user());

        return response()->noContent();
    }

    /**
     * Применить промокод
     *
     * Применяет промокод к товарам указанного магазина в корзине.
     * Промокод со scope=platform применяется ко всей корзине (запись
     * cart_promocodes со store_id=null). Если storeId не передан, а промокод
     * не platform-wide — 422.
     *
     * Все отказы (промокод не найден, истёк, исчерпан лимит, не подходит магазину
     * или товарам, не достигнута минимальная сумма) отдаются как 422
     * {message, errors: {promocode: [...]}}.
     */
    public function applyPromocode(ApplyPromocodeRequest $request, ApplyPromocodeAction $action): CartResource|ClientCartResource
    {
        $storeIdRaw = $request->validated('storeId');
        $storeId = $storeIdRaw === null ? null : (int) $storeIdRaw;

        $cart = $action->execute(
            $request->user(),
            $storeId,
            $request->validated('promocode')
        );

        return $this->cartWithAddress($cart, $request->user());
    }

    /**
     * Удалить промокод
     *
     * Удаляет применённый промокод для указанного магазина.
     * Если storeId не передан — удаляется platform-wide промокод.
     * Отсутствие записи промокода ошибкой не считается.
     * Если у пользователя нет корзины — отдаётся пустая форма корзины.
     */
    public function removePromocode(RemovePromocodeRequest $request, RemovePromocodeAction $action): CartResource|ClientCartResource|JsonResponse
    {
        $storeIdRaw = $request->validated('storeId');
        $storeId = $storeIdRaw === null ? null : (int) $storeIdRaw;

        $cart = $action->execute($request->user(), $storeId);

        if (! $cart) {
            return $this->emptyCartResponse();
        }

        return $this->cartWithAddress($cart, $request->user());
    }

    /**
     * Очистить группу магазина
     *
     * Удаляет все позиции указанного магазина из корзины (дочерние позиции —
     * каскадом) и промокод этого магазина. Если у пользователя нет корзины —
     * отдаётся пустая форма корзины.
     */
    public function clearStoreGroup(Request $request, Store $store, ClearStoreGroupAction $action): CartResource|ClientCartResource|JsonResponse
    {
        $cart = $action->execute($request->user(), $store->id);

        if (! $cart) {
            return $this->emptyCartResponse();
        }

        return $this->cartWithAddress($cart, $request->user());
    }

    /**
     * Переключить корзину на магазин
     *
     * Вызывается после смены адреса доставки или магазина самовывоза: позиции
     * корзины перепроверяются по ассортименту целевого магазина (сетевой
     * каталог). Магазин — storeId запроса, иначе первый доступный по активному
     * адресу пользователя; ни того ни другого — 422.
     *
     * Позиция с валидным оффером в целевом магазине перепривязывается к нему
     * и получает снапшот его цены; одинаковые позиции сливаются в одну.
     * Позиция без оффера или без остатка (если магазин учитывает остатки)
     * удаляется и попадает в removedItems; товарные добавки едут вместе
     * с родителем, нет оффера у добавки — удаляется вся позиция. Промокоды
     * магазинов, в которых не осталось позиций, снимаются.
     *
     * В обычном (не сетевом) режиме корзина не меняется, removedItems пуст.
     *
     * Ответ — корзина (CartResource / ClientCartResource, как GET /cart) плюс:
     * storeId — целевой магазин; removedItems — удалённые позиции в форме
     * CartItem обычной корзины (та же карточка, что в storeGroups[].items[],
     * в контексте магазина, где позиция лежала до переключения) с полями
     * productId и title рядом (прежний контракт); hasUnavailableItems — true,
     * если хоть одна позиция удалена (подсказка «Некоторых товаров нет
     * в наличии», затемнённые карточки удалённых позиций).
     *
     * @response array{storeGroups: array, weightTotal: float|null, isHeavy: bool, availableShippingMethods: array, storeId: int, removedItems: array<array{productId: int, title: string, id: int, product: \App\Http\Resources\Api\V1\ProductResource, quantity: int, price: float, priceOld: float|null, discountPercent: int|null, quantityInStock: int|null, stockWarning: string|null, supplements: \App\Http\Resources\Api\V1\CartItemSupplementResource[], supplementsTotal: int}>, hasUnavailableItems: bool}
     */
    #[\Dedoc\Scramble\Attributes\Response(422, description: 'Магазин не определился или не найден', type: 'array{message: string, errors: array{storeId: string[]}}')]
    public function switchStore(SwitchCartStoreRequest $request, SwitchCartStoreAction $action): JsonResponse
    {
        $storeIdRaw = $request->validated('storeId');

        $result = $action->execute(
            $request->user(),
            $storeIdRaw === null ? null : (int) $storeIdRaw,
        );

        $cartPayload = $result['cart'] === null
            ? $this->emptyCartResponse()->getData(true)
            : $this->cartWithAddress($result['cart'], $request->user())->resolve($request);

        return response()->json($cartPayload + [
            'storeId' => $result['storeId'],
            'removedItems' => $result['removedItems'],
            'hasUnavailableItems' => $result['removedItems'] !== [],
        ]);
    }

    /**
     * Обернуть Cart в CartResource/ClientCartResource с автоподтягиванием активного адреса.
     * Выбор Resource зависит от marketplace mode.
     */
    private function cartWithAddress(Cart $cart, User $user): CartResource|ClientCartResource
    {
        // Сетевой каталог: карточка в ответе должна показывать цену и остаток
        // магазина покупки позиции, а не нули глобальной карточки.
        $this->storeOffers->attachToCart($cart);
        $this->priceResolver->preloadCartItems($cart->items);

        $address = Address::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->first();

        if ($this->moduleConfigService->isMarketplaceMode()) {
            return (new ClientCartResource($cart))->withAddress($address);
        }

        return (new CartResource($cart))->withAddress($address);
    }

    private function emptyCartResponse(): JsonResponse
    {
        if ($this->moduleConfigService->isMarketplaceMode()) {
            return response()->json([
                'dateGroups' => [],
                'deliveryGroups' => [],
                'totalAmount' => 0.0,
                'totalAmountBeforeDiscount' => 0.0,
                'totalDiscount' => 0.0,
                'totalShippingPrice' => 0.0,
                'totalItemsCount' => 0,
                'promotionDiscount' => 0.0,
                'vatAmount' => 0.0,
                'totalToPay' => 0.0,
                'freeShippingFrom' => $this->moduleConfigService->getMarketplaceFreeShippingThreshold(),
                'isMultiDate' => false,
                'estimatedDates' => [],
                'promocode' => null,
                'weightTotal' => null,
                'isHeavy' => false,
                'bonus' => null,
            ]);
        }

        return response()->json([
            'storeGroups' => [],
            'weightTotal' => null,
            'isHeavy' => false,
        ]);
    }

    /**
     * Синхронизирует цены в корзине с актуальными ценами товаров (с учётом акций).
     *
     * Помимо price обновляет и снапшот price_old: скидка, назначенная товару
     * после добавления в корзину, должна появиться в позиции, а снятая — исчезнуть.
     *
     * Сетевой каталог: источник цены — оффер магазина покупки, навешенный
     * контекстом карточки ({@see CartStoreOffers::attachToCart()}). Позицию без
     * оффера не трогаем: её цену нельзя пересчитать по нулевым колонкам
     * глобальной карточки, а саму позицию снимет removeUnavailableItems().
     */
    private function syncPrices(Cart $cart): void
    {
        $network = $this->storeOffers->isNetworkMode();

        foreach ($cart->items as $item) {
            $product = $item->product;

            // Товар удалён (soft-delete) после добавления в корзину: пересчитывать
            // нечего, позицию снимет removeUnavailableItems().
            if ($product === null) {
                continue;
            }

            if ($network && $product?->storeContext() === null) {
                continue;
            }

            // Цены от количества (MID-308): корневая позиция котируется по своему
            // количеству, иначе пересчёт затирал бы ступень обычной ценой товара.
            // Дочерние позиции (товарные добавки) ступенями не затрагиваются.
            if ($item->parent_id === null) {
                $quote = $this->priceResolver->quote($product, (float) $item->portions);
                $effectivePrice = $quote->price;
                $originalPrice = $quote->priceOld;
            } else {
                $effectivePrice = $product->getEffectivePrice();
                $originalPrice = $product->getOriginalPrice();
            }

            $updates = [];
            if ($item->price !== $effectivePrice) {
                $updates['price'] = $effectivePrice;
            }
            if ($item->price_old !== $originalPrice) {
                $updates['price_old'] = $originalPrice;
            }

            if ($updates !== []) {
                $item->update($updates);
            }
        }
    }

    /**
     * Удаляет из корзины товары, которые недоступны.
     *
     * Сетевой каталог: доступность считает оффер магазина покупки — карточка
     * сети всегда с нулевым остатком, и легаси-проверка вычистила бы корзину
     * целиком. Нет валидного оффера — товар в этом магазине не продаётся.
     *
     * Активность самого магазина здесь СОЗНАТЕЛЬНО не проверяется: легаси так
     * никогда не делал, а временно выключенный магазин молча вычищал бы уже
     * собранную корзину. Магазин гейтится на входе — правилом storeId
     * (exists + is_active) при добавлении.
     */
    private function removeUnavailableItems(Cart $cart): void
    {
        $network = $this->storeOffers->isNetworkMode();

        foreach ($cart->items as $item) {
            $product = $item->product;

            if (! $product || $product->trashed() || ! $product->is_active) {
                $item->delete();

                continue;
            }

            $offer = $network ? $product->storeContext() : null;

            if ($network && $offer === null) {
                $item->delete();

                continue;
            }

            $stockQuantity = $network ? (int) $offer->quantity : (int) $product->quantity;

            // В режиме ignore_stock нулевой остаток не делает товар недоступным.
            if ($stockQuantity <= 0 && ! $product->isStockIgnored()) {
                $item->delete();
            }
        }
    }
}
