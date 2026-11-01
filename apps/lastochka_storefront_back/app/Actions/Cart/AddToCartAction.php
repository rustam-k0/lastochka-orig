<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Actions\Cart\Concerns\HandlesSupplements;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\StoreAccessDeniedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use App\Services\Cart\CartStoreOffers;
use App\Services\Pricing\ProductPriceResolver;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class AddToCartAction
{
    use HandlesSupplements;

    public function __construct(
        private readonly CartStoreOffers $storeOffers,
        private readonly ProductPriceResolver $priceResolver,
    ) {
    }

    /**
     * Добавить товар в корзину.
     *
     * @param  array<array{supplementId: int, valueIds: int[]}>|null  $supplements
     * @param  int|null  $storeId  магазин покупки (сетевой каталог); в легаси-режиме игнорируется
     *
     * @throws InsufficientStockException
     * @throws StoreAccessDeniedException
     */
    public function execute(
        User $user,
        int $productId,
        int $quantity,
        ?array $supplements = null,
        ?string $deliveryDate = null,
        ?int $storeId = null,
    ): Cart {
        // carts_user_id_unique гонка: при конкурентном ПЕРВОМ добавлении два запроса разом
        // создают cart -> UniqueConstraintViolationException (23000). DB::transaction attempts
        // ретраит только deadlock (40001), не 23000 -> ретраим всю транзакцию: на свежей
        // транзакции (новый RR-snapshot) firstOrCreate увидит уже закоммиченную корзину.
        $attempts = 3;
        for ($i = 1; ; $i++) {
            try {
                return $this->addToCartTransaction($user, $productId, $quantity, $supplements, $deliveryDate, $storeId);
            } catch (UniqueConstraintViolationException $e) {
                if ($i >= $attempts) {
                    throw $e;
                }
                usleep(random_int(5, 20) * 1000);
            }
        }
    }

    /**
     * @param  array<array{supplementId: int, valueIds: int[]}>|null  $supplements
     */
    private function addToCartTransaction(
        User $user,
        int $productId,
        int $quantity,
        ?array $supplements,
        ?string $deliveryDate,
        ?int $storeId,
    ): Cart {
        return DB::transaction(function () use ($user, $productId, $quantity, $supplements, $deliveryDate, $storeId) {
            $product = Product::query()
                ->active()
                ->findOrFail($productId);

            // Нельзя добавить в корзину parent вариативного товара — только конкретный variant.
            if ($product->is_configurable) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => 'Товар не найден или является вариативным (выберите конкретный вариант)',
                        'errors' => ['productId' => ['Товар не найден или является вариативным (выберите конкретный вариант)']],
                    ], 422)
                );
            }

            // Магазин покупки и источник цены/остатка позиции.
            //
            // Сетевой каталог: карточка глобальна (её store_id — якорный магазин,
            // цена и остаток нулевые), поэтому магазин покупки резолвится из
            // запроса/адреса, а снапшот цены берётся из его оффера. Легаси:
            // магазин и цена — у карточки, как было.
            $purchaseStore = $this->resolvePurchaseStore($product, $user, $storeId);
            $purchaseStoreId = $purchaseStore['storeId'];
            $availableQuantity = $purchaseStore['availableQuantity'];

            // B2B-гейт (зеркально Store::scopeForUserType): применяется только при
            // включённом модуле b2b; B2B-клиент покупает в любых магазинах,
            // B2C-клиент — только в не-B2B (ALADDIN-10197).
            if (app(ModuleConfigService::class)->isB2bEnabled()) {
                $userIsB2b = $user->is_b2b ?? false;
                // В сетевом режиме гейтит магазин ПОКУПКИ: у якорной карточки
                // свой магазин-владелец, к доступу клиента он отношения не имеет.
                $gateStore = $this->storeOffers->isNetworkMode()
                    ? $product->storeContext()?->store
                    : $product->store;
                $storeIsB2b = $gateStore?->is_b2b ?? false;
                if (! $userIsB2b && $storeIsB2b) {
                    throw new StoreAccessDeniedException;
                }
            }

            // Проверить что количество кратно шагу
            $step = $product->quantity_step ?? 1;
            if ($step > 1 && $quantity % $step !== 0) {
                throw new \Illuminate\Validation\ValidationException(
                    validator: validator([], []),
                    response: response()->json([
                        'message' => "Количество должно быть кратно {$step}",
                        'errors' => ['quantity' => ["Количество должно быть кратно {$step}"]],
                    ], 422)
                );
            }

            // Проверить обязательные добавки
            if ($supplements === null) {
                $hasRequired = Supplement::where('product_id', $productId)
                    ->where('is_required', true)
                    ->exists();

                if ($hasRequired) {
                    throw new \Illuminate\Validation\ValidationException(
                        validator: validator([], []),
                        response: response()->json([
                            'message' => 'Не все обязательные добавки выбраны',
                            'errors' => ['supplements' => ['Не все обязательные добавки выбраны']],
                        ], 422)
                    );
                }
            }

            $cart = Cart::firstOrCreate(['user_id' => $user->id]);

            // Нормализовать добавки и разделить на виртуальные и товарные
            $normalized = $this->normalizeSupplements($supplements);
            $split = $this->splitSupplements($normalized);

            // Определить набор добавок для сравнения (включая товарные)
            $supplementsKey = $this->buildSupplementsKey($split['virtual'], $split['catalog']);

            // Найти существующий CartItem с тем же набором добавок.
            // В сетевом режиме позиции разных магазинов не сливаются: у каждой
            // свой снапшот цены оффера, слияние затёрло бы чужую цену.
            $existingItem = $this->findMatchingCartItem(
                $cart,
                $productId,
                $supplementsKey,
                $this->storeOffers->isNetworkMode() ? $purchaseStoreId : null,
            );

            $totalQuantity = $existingItem
                ? $existingItem->quantity + $quantity
                : $quantity;

            // Остаток в г/мл/шт, корзина товара «по кг/л» — в кг/л: сравниваем
            // в единицах остатка, в ошибке показываем в единицах корзины.
            if (! $product->isStockIgnored() && $product->cartQuantityInStockUnits($totalQuantity) > $availableQuantity) {
                throw new InsufficientStockException(
                    $product->title,
                    intdiv($availableQuantity, $product->stockUnitsPerCartUnit()),
                    $totalQuantity
                );
            }

            // Цены от количества (MID-308): цена позиции зависит от ИТОГОВОГО
            // количества (при слиянии: уже лежащее плюс добавляемое). Флаг выключен
            // или лесенки нет: котировка равна getEffectivePrice()/getOriginalPrice().
            // В сетевом каталоге оффер магазина покупки уже навешен resolvePurchaseStore().
            $quote = $this->priceResolver->quote(
                $product,
                $this->priceResolver->portionsFor($product, $totalQuantity)
            );
            $effectivePrice = $quote->price;
            $originalPrice = $quote->priceOld;

            if ($existingItem) {
                // Загружаем product для корректного расчёта portions у весовых товаров.
                $existingItem->loadMissing('product');
                $oldPortions = max(1, $existingItem->getPortionsAttribute());

                // Рассчитаем новые порции заблаговременно (до update), чтобы проверить остаток.
                $newStep = $product->quantity_step ?? 1;
                $newPortionsPrecheck = $newStep > 1 ? intdiv($totalQuantity, $newStep) : $totalQuantity;
                $newPortionsPrecheck = max(1, $newPortionsPrecheck);

                // Проверить остатки товарных добавок ДО обновления, чтобы транзакция
                // откатилась без частичных изменений.
                foreach ($existingItem->childItems as $child) {
                    $sv = $child->sourceSupplementValue;
                    if ($sv && $sv->tracksStock()) {
                        $perUnit = (int) round($child->quantity / $oldPortions);
                        $newQty = $perUnit * $newPortionsPrecheck;
                        $supplementProduct = $child->product;
                        // Остаток добавки — в её магазине покупки (сетевой режим),
                        // иначе колонка карточки.
                        $childStock = $supplementProduct !== null
                            ? $this->storeOffers->availableStock($supplementProduct, (int) $child->store_id)
                            : 0;
                        if ($supplementProduct && $newQty > $childStock) {
                            throw new InsufficientStockException(
                                $supplementProduct->title,
                                $childStock,
                                $newQty
                            );
                        }
                    }
                }

                $updateData = [
                    'quantity' => $totalQuantity,
                    'price' => $effectivePrice,
                    'price_old' => $originalPrice,
                ];

                // Не затираем существующую delivery_date null'ом.
                // Обновляем только если передано non-null значение.
                if ($deliveryDate !== null) {
                    $updateData['delivery_date'] = $deliveryDate;
                }

                $existingItem->update($updateData);
                $newPortions = max(1, $existingItem->fresh()->getPortionsAttribute());

                // Пересчитываем дочерние позиции (товарные добавки).
                foreach ($existingItem->childItems as $child) {
                    $perUnit = (int) round($child->quantity / $oldPortions);
                    $child->update(['quantity' => $perUnit * $newPortions]);
                }
            } else {
                $cartItem = $cart->items()->create([
                    'product_id' => $product->id,
                    'store_id' => $purchaseStoreId,
                    'quantity' => $quantity,
                    'price' => $effectivePrice,
                    'price_old' => $originalPrice,
                    'delivery_date' => $deliveryDate,
                ]);

                // Создать виртуальные добавки
                if ($split['virtual']) {
                    $this->createCartItemSupplements($cartItem, $split['virtual']);
                }

                // Создать дочерние позиции для товарных добавок.
                // Вычисляем portions из $product (загружен в начале execute), а НЕ из
                // $cartItem: свежесозданный CartItem не имеет загруженного relation `product`,
                // поэтому $cartItem->getPortionsAttribute() всегда даёт step=1 и
                // для весовых товаров (step>1) возвращает quantity вместо quantity/step.
                if ($split['catalog']) {
                    $rootStep = $product->quantity_step ?? 1;
                    $rootPortions = $rootStep > 1 ? intdiv($quantity, $rootStep) : $quantity;
                    $this->createCatalogChildren($cart, $cartItem, $split['catalog'], $rootPortions);
                }
            }

            return $cart->fresh(Cart::RESPONSE_RELATIONS);
        }, attempts: 3); // retry при deadlock (SQLSTATE 40001) от lockForUpdate на cart_items под конкурентной записью
    }

    /**
     * Магазин покупки позиции и остаток, по которому проверяется добавление.
     *
     * Сетевой каталог: магазин — явный storeId запроса, иначе первый доступный
     * магазин активного адреса; товар обязан иметь в нём валидный оффер
     * (активный, с ценой) — он же становится источником цены карточки
     * ({@see Product::storeContext()}), поэтому дальше getEffectivePrice()
     * снапшотит цену магазина, а не нули глобальной карточки.
     *
     * Легаси-режим: магазин и остаток — у карточки, ровно как раньше.
     *
     * @return array{storeId: int, availableQuantity: int}
     */
    private function resolvePurchaseStore(Product $product, User $user, ?int $requestedStoreId): array
    {
        if (! $this->storeOffers->isNetworkMode()) {
            return [
                'storeId' => (int) $product->store_id,
                'availableQuantity' => (int) $product->quantity,
            ];
        }

        $storeId = $this->storeOffers->resolvePurchaseStoreId($requestedStoreId, $user);

        if ($storeId === 0) {
            throw new \Illuminate\Validation\ValidationException(
                validator: validator([], []),
                response: response()->json([
                    'message' => 'Не удалось определить магазин: передайте storeId или выберите адрес',
                    'errors' => ['storeId' => ['Не удалось определить магазин: передайте storeId или выберите адрес']],
                ], 422)
            );
        }

        $offer = $this->storeOffers->offerFor((int) $product->id, $storeId);

        if ($offer === null) {
            throw new \Illuminate\Validation\ValidationException(
                validator: validator([], []),
                response: response()->json([
                    'message' => 'Товар недоступен в выбранном магазине',
                    'errors' => ['productId' => ['Товар недоступен в выбранном магазине']],
                ], 422)
            );
        }

        $this->storeOffers->attachOffer($product, $offer);

        return [
            'storeId' => $storeId,
            'availableQuantity' => (int) $offer->quantity,
        ];
    }

    /**
     * Построить ключ для идентификации уникального набора добавок.
     * Включает виртуальные добавки (supplementId:valueIds) и товарные (#productId:perUnitQty).
     *
     * @param  array<array{supplementId:int, valueIds:int[]}>  $virtual
     * @param  array<array{valueId:int, quantity:int, value:SupplementValue}>  $catalog
     */
    private function buildSupplementsKey(array $virtual, array $catalog): string
    {
        $vKey = collect($virtual)->sortBy('supplementId')->map(function ($i) {
            $ids = $i['valueIds'];
            sort($ids);

            return $i['supplementId'].':'.implode(',', $ids);
        })->implode('|');

        $cKey = collect($catalog)->sortBy('valueId')->map(
            fn ($c) => '#'.$c['value']->product_id.':'.$c['quantity']
        )->implode('|');

        return trim($vKey.'||'.$cKey, '|');
    }

    /**
     * Найти CartItem с тем же product_id и набором добавок (включая товарные).
     * $storeId !== null — дополнительно ограничить магазином покупки
     * (сетевой каталог).
     */
    private function findMatchingCartItem(Cart $cart, int $productId, string $supplementsKey, ?int $storeId = null): ?CartItem
    {
        $items = $cart->items()
            ->where('product_id', $productId)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->whereNull('parent_id')
            ->lockForUpdate()
            ->with(['supplements.values', 'childItems.sourceSupplementValue.product', 'childItems.product', 'product'])
            ->get();

        foreach ($items as $item) {
            $itemKey = $this->buildSupplementsKeyFromCartItem($item);
            if ($itemKey === $supplementsKey) {
                return $item;
            }
        }

        return null;
    }
}
