<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Address;
use App\Models\Cart;
use App\Services\Cart\CartCalculator;
use App\Services\Cart\StoreDeliveryCostResolver;
use App\Services\Discount\ItemDiscountResolver;
use App\Services\Settings\ModuleConfigService;
use App\Support\Vat;
use App\Support\WeightAggregator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Cart shape для marketplace режима — группировка по датам доставки, без упоминания магазинов.
 *
 * Shape:
 * {
 *   dateGroups: [
 *     {date: ?string, items: [...], subtotal: int, itemsCount: int}
 *   ],
 *   totalAmount: float,
 *   totalShippingPrice: float,
 *   isMultiDate: bool,
 *   estimatedDates: [?string],
 * }
 *
 * Группирует items по `CartItem.delivery_date`. Items без даты попадают в отдельную
 * группу с ключом `date=null`.
 *
 * @mixin Cart
 */
final class ClientCartResource extends JsonResource
{
    public static $wrap = null;

    private ?Address $address = null;

    public function withAddress(?Address $address): static
    {
        $this->address = $address;

        return $this;
    }

    /**
     * @return array{
     *     dateGroups: list<array{date: string|null, items: CartItemResource[], subtotal: int, itemsCount: int, weightTotal: float|null, availableFrom: string|null}>,
     *     totalAmount: float,
     *     totalAmountBeforeDiscount: float,
     *     totalDiscount: float,
     *     totalShippingPrice: float,
     *     totalItemsCount: int,
     *     promotionDiscount: float,
     *     vatAmount: float,
     *     totalToPay: float,
     *     bonus: array{isEnabled: bool, eligibleAmount: int, maxEarn: int, maxSpend: int, userBalance: int}|null,
     *     freeShippingFrom: int|null,
     *     isMultiDate: bool,
     *     estimatedDates: list<string|null>,
     *     promocode: array{code: string, type: string, value: float, discount: int, appliedToItemsCount: int, totalItemsCount: int, isPartialApplication: bool}|null,
     *     weightTotal: float|null,
     *     isHeavy: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $calculator = app(CartCalculator::class);
        $moduleConfig = app(ModuleConfigService::class);

        /** @var Collection $items */
        $items = $this->items ?? collect();

        $address = $this->address;

        $dateGroups = $this->groupItemsByDeliveryDate($items);
        $renderedGroups = [];
        $totalAmount = 0.0;
        $totalShippingPrice = 0.0;
        $itemsByStoreForShipping = $items->groupBy('store_id');

        foreach ($dateGroups as $date => $groupItems) {
            $groupSubtotal = round((float) $groupItems->sum(fn ($item) => (float) $item->price * (int) ($item->portions ?? $item->quantity)), 2);
            $totalAmount += $groupSubtotal;

            $renderedGroups[] = [
                'date' => $date !== '' ? $date : null,
                'items' => CartItemResource::collection($groupItems),
                'subtotal' => $groupSubtotal,
                'itemsCount' => (int) $groupItems->count(),
                'weightTotal' => WeightAggregator::toKg(WeightAggregator::sumGrams($groupItems)),
                'availableFrom' => app(\App\Services\ReadyTime\ReadyTimeResolver::class)
                    ->forCartItems($groupItems)?->toIso8601String(),
            ];
        }

        // ==== Блок «Итого» (Platrum 11784) ====

        // «N товаров …» — только корневые позиции (дочерние добавки не считаются),
        // зеркало orders.items_count в CreateOrderAction.
        $totalItemsCount = $items->whereNull('parent_id')->count();

        // Товарная скидка от акций/старых цен:
        // Σ (price_old − price) × portions по позициям с price_old > price.
        // Не вычитается из totalAmount — цены позиций уже акционные (syncPrices);
        // участвует в totalAmountBeforeDiscount и totalDiscount ниже.
        $promotionDiscount = round((float) $items->sum(function ($item) {
            $priceOld = (float) ($item->price_old ?? 0);
            $price = (float) $item->price;

            return $priceOld > $price ? ($priceOld - $price) * (int) $item->portions : 0;
        }), 2);

        // Строка «в т.ч. НДС»: НДС включён в цену, считается по позициям из
        // products.vat_rate (код ставки ЮKassa; null → дефолтный код конфига).
        // Промокод/бонусы строку не корректируют — точный расчёт делает
        // фискализация чека (OrderReceiptResource) после оформления.
        $vatAmount = round((float) $items->sum(function ($item) {
            $rate = Vat::rateFromCode((int) ($item->product?->vat_rate ?? Vat::defaultCode()));

            return Vat::includedVat((float) $item->price * (int) $item->portions, $rate);
        }), 2);

        // Platform-wide промокод — один на всю корзину (store_id=null в cart_promocodes).
        $promocodeBlock = null;
        $discount = 0;
        $platformWideCartPromocode = $this->promocodes
            ->whereNull('store_id')
            ->first();
        $platformWidePromocode = $platformWideCartPromocode?->promocode;

        if ($platformWidePromocode && $platformWidePromocode->isPlatformWide()) {
            $discount = $calculator->calculateDiscountForItems($items, $platformWidePromocode);

            $itemDiscount = app(ItemDiscountResolver::class);
            $appliedItems = $items->filter(fn ($item) => $item->product
                && ! $itemDiscount->hasDiscount($item)
                && $platformWidePromocode->matchesProduct($item->product));

            $totalItemsCount = $items->count();
            $appliedToItemsCount = $appliedItems->count();

            $promocodeBlock = [
                'code' => (string) $platformWidePromocode->code,
                'type' => $platformWidePromocode->type instanceof \BackedEnum
                    ? (string) $platformWidePromocode->type->value
                    : (string) $platformWidePromocode->type,
                'value' => (float) $platformWidePromocode->value,
                'discount' => round((float) $discount, 2),
                'appliedToItemsCount' => (int) $appliedToItemsCount,
                'totalItemsCount' => (int) $totalItemsCount,
                'isPartialApplication' => (bool) ($totalItemsCount > 0 && $appliedToItemsCount < $totalItemsCount),
            ];
        }

        // ==== Агрегированный блок бонусов (Platrum 11784) ====
        // Зеркало per-store блока стандартного CartResource + previewBonus:
        // считаем по магазинам с is_bonus_enabled; eligible — товары без скидки;
        // применённый платформенный промокод обнуляет квоты (бонусы и промокод
        // взаимоисключающи). userBalance — доступный остаток (balance − reserved).
        $bonusBlock = null;
        $bonusStoreGroups = $itemsByStoreForShipping
            ->filter(fn ($storeItems) => (bool) ($storeItems->first()->store?->is_bonus_enabled ?? false));

        if ($bonusStoreGroups->isNotEmpty()) {
            $userBalance = (int) (Auth::user()?->available_bonus_balance ?? 0);
            $eligibleTotal = 0;
            $maxEarn = 0;
            $maxSpendQuota = 0;

            if ($promocodeBlock === null) {
                $bonusItemDiscount = app(ItemDiscountResolver::class);

                foreach ($bonusStoreGroups as $storeItems) {
                    $store = $storeItems->first()->store;
                    $eligible = (int) $storeItems
                        ->filter(fn ($item) => $item->product && ! $bonusItemDiscount->hasDiscount($item))
                        ->sum(fn ($item) => $item->price * $item->portions);

                    if ($eligible <= 0) {
                        continue;
                    }

                    $eligibleTotal += $eligible;
                    $maxEarn += (int) floor($eligible * (int) ($store->bonus_earn_percent ?? 0) / 100);
                    $maxSpendQuota += (int) floor($eligible * (int) ($store->bonus_spend_percent ?? 0) / 100);
                }
            }

            $bonusBlock = [
                'isEnabled' => true,
                'eligibleAmount' => $eligibleTotal,
                'maxEarn' => $maxEarn,
                'maxSpend' => min($maxSpendQuota, $userBalance),
                'userBalance' => $userBalance,
            ];
        }

        // Shipping price — агрегат по магазинам (marketplace-wide threshold или per-store).
        // pickup — собственной доставки нет (0). cdek/russian_post — тариф ПВЗ
        // определяется выбранным пунктом выдачи и считается при создании заказа
        // (ThirdPartyTariffPriceResolver); в корзине он ещё неизвестен → 0,
        // мобилка добавляет тариф выбранного ПВЗ на своей стороне.
        $shippingMethod = (string) $request->query('shippingMethod', 'courier');
        $marketplaceFreeShippingThreshold = $moduleConfig->getMarketplaceFreeShippingThreshold();
        $crossesMarketplaceThreshold = $marketplaceFreeShippingThreshold !== null
            && $totalAmount >= $marketplaceFreeShippingThreshold;

        if (in_array($shippingMethod, ['pickup', 'cdek', 'russian_post'], true)) {
            $totalShippingPrice = 0.0;
        } elseif (! $crossesMarketplaceThreshold) {
            // Расчёт по магазину общий со сводкой доставки: Яндекс — checkPrice,
            // иначе лесенка зоны по адресу или магазина.
            $storeDeliveryCost = app(StoreDeliveryCostResolver::class);

            foreach ($itemsByStoreForShipping as $storeItems) {
                $store = $storeItems->first()->store ?? null;
                if ($store === null) {
                    continue;
                }

                $totalShippingPrice += $storeDeliveryCost->resolve($store, $storeItems, $address);
            }
        }

        $estimatedDates = array_values(array_unique(array_map(
            fn ($group) => $group['date'],
            $renderedGroups,
        )));
        // isMultiDate считается только по непустым датам — items без выбранной даты
        // (date=null) не учитываются. Один date + один null → не multi-date.
        $isMultiDate = count(array_filter($estimatedDates, fn ($d) => $d !== null)) > 1;

        $totalAmountAfterDiscount = (float) max(0, $totalAmount - $discount);

        // «До скидки» — сумма по старым ценам (price_old ?? price) = текущая
        // сумма позиций + товарная скидка. totalDiscount — вся скидка целиком:
        // товарная (акции/price_old) + платформенный промокод (Platrum 11746).
        $totalAmountBeforeDiscount = round($totalAmount + $promotionDiscount, 2);
        $totalDiscount = round($promotionDiscount + $discount, 2);

        return [
            'dateGroups' => $renderedGroups,
            // Технический блок для courier/pickup сабмита: мобилка по нему знает,
            // для каких storeId запрашивать delivery-slots/pickup-slots и собирать
            // deliverySlots в POST /client-orders. Названия магазинов не раскрываем.
            'deliveryGroups' => self::deliveryGroupsFor($items),
            'totalAmount' => (float) $totalAmountAfterDiscount,
            'totalAmountBeforeDiscount' => (float) $totalAmountBeforeDiscount,
            'totalDiscount' => (float) $totalDiscount,
            'totalShippingPrice' => (float) $totalShippingPrice,
            'totalItemsCount' => (int) $totalItemsCount,
            'promotionDiscount' => (float) $promotionDiscount,
            'vatAmount' => (float) $vatAmount,
            'totalToPay' => (float) round($totalAmountAfterDiscount + $totalShippingPrice, 2),
            'bonus' => $bonusBlock,
            'freeShippingFrom' => $marketplaceFreeShippingThreshold === null ? null : (int) $marketplaceFreeShippingThreshold,
            'isMultiDate' => (bool) $isMultiDate,
            'estimatedDates' => $estimatedDates,
            'promocode' => $promocodeBlock,
            'weightTotal' => WeightAggregator::toKg(WeightAggregator::sumGrams($items)),
            'isHeavy' => $this->computeIsHeavy($items),
            'availableShippingMethods' => app(\App\Services\Cart\AvailableShippingMethodsResolver::class)
                ->resolve($this->resource),
        ];
    }

    /**
     * Группы доставки по магазинам корзины: {storeId, itemIds, itemsCount}.
     * Только корневые позиции — дочерние (товарные добавки) едут с родителем.
     * Используется также в CartController::deliverySummary.
     *
     * @return array<int, array{storeId: int, itemIds: array<int, int>, itemsCount: int}>
     */
    public static function deliveryGroupsFor(Collection $items): array
    {
        return $items
            ->whereNull('parent_id')
            ->groupBy('store_id')
            ->sortKeys()
            ->map(static fn (Collection $storeItems, $storeId): array => [
                'storeId' => (int) $storeId,
                'itemIds' => $storeItems->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                'itemsCount' => $storeItems->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Тяжёлый заказ в marketplace-корзине: хотя бы одна store-группа (корневые
     * позиции магазина) строго превышает порог heavy_order_threshold магазина.
     */
    private function computeIsHeavy(Collection $items): bool
    {
        $heavyResolver = app(\App\Support\HeavyOrderResolver::class);

        return $items->whereNull('parent_id')
            ->groupBy('store_id')
            ->contains(fn ($storeItems) => $heavyResolver->isHeavyForItems($storeItems->first()?->store, $storeItems));
    }

    /**
     * Группирует items по delivery_date. Если поле отсутствует — single group с ключом ''.
     *
     * @return array<string, Collection>
     */
    private function groupItemsByDeliveryDate(Collection $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $date = $this->resolveItemDeliveryDate($item);
            $key = $date ?? '';
            $grouped[$key] ??= collect();
            $grouped[$key]->push($item);
        }

        if ($grouped === []) {
            $grouped[''] = collect();
        }

        ksort($grouped);

        return $grouped;
    }

    private function resolveItemDeliveryDate(object $item): ?string
    {
        $deliveryDate = $item->delivery_date ?? null;

        if ($deliveryDate === null) {
            return null;
        }

        if ($deliveryDate instanceof \DateTimeInterface) {
            return $deliveryDate->format('Y-m-d');
        }

        return (string) $deliveryDate;
    }
}
