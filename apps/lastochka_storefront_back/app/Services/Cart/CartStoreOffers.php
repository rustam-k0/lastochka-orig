<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\StoreProduct;
use App\Models\SupplementValue;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use App\Services\Store\AvailableStoreService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Офферы магазина ПОКУПКИ для корзины и резервирования (network_catalog).
 *
 * В сетевом каталоге карточка товара глобальна (принадлежит якорному магазину),
 * а цена/остаток живут в store_products конкретного магазина. Корзина обязана
 * зафиксировать магазин покупки в cart_items.store_id и снапшотить цену его
 * оффера — иначе позиция уедет в заказ с нулевой ценой якорной карточки.
 *
 * Единый источник правды для корзинных путей: добавление, изменение,
 * пересчёт выдачи, резервирование. При выключенном модуле все методы
 * возвращают легаси-значения (колонки products), а attach-методы ничего
 * не делают — корзина остаётся байт-в-байт прежней.
 */
final class CartStoreOffers
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
        private readonly AvailableStoreService $availableStores,
    ) {}

    public function isNetworkMode(): bool
    {
        return $this->moduleConfig->isNetworkCatalogEnabled();
    }

    /**
     * Магазин покупки: явный storeId запроса, иначе первый доступный магазин
     * активного адреса пользователя. 0 — определить не удалось (вызывающий
     * отдаёт 422: клиенту нужно выбрать адрес или передать storeId).
     *
     * Резолвится ТОЛЬКО в сетевом режиме: в легаси магазин берётся у карточки
     * товара, а storeId запроса игнорируется.
     */
    public function resolvePurchaseStoreId(?int $requestedStoreId, User $user): int
    {
        if (! $this->isNetworkMode()) {
            return 0;
        }

        if ($requestedStoreId !== null && $requestedStoreId > 0) {
            return $requestedStoreId;
        }

        $address = Address::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($address === null) {
            return 0;
        }

        return (int) ($this->availableStores
            ->getAvailableStores($address, $user)
            ->first()?->id ?? 0);
    }

    /**
     * Валидный оффер магазина: активная строка store_products с ценой.
     * Строка с price=0 (её создаёт stock-событие, пришедшее раньше цены)
     * оффером не считается — те же условия, что у каталожной выдачи
     * ({@see \App\Services\Catalog\StoreOfferContext::eagerLoad()}).
     *
     * Магазин оффера подгружаем сразу — его читает Product::isStockIgnored().
     */
    public function offerFor(int $productId, int $storeId, bool $lockForUpdate = false): ?StoreProduct
    {
        if ($storeId <= 0) {
            return null;
        }

        $query = StoreProduct::query()
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->where('price', '>', 0);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $offer = $query->first();

        $offer?->loadMissing('store');

        return $offer;
    }

    /**
     * Остаток, по которому проверяется добавление в корзину и резервирование:
     * в сетевом режиме — остаток оффера магазина покупки (нет оффера = нет
     * товара), в легаси — колонка products.quantity.
     */
    public function availableStock(Product $product, ?int $storeId): int
    {
        if (! $this->isNetworkMode()) {
            return (int) $product->quantity;
        }

        $offer = $this->offerFor((int) $product->id, (int) $storeId);

        return $offer !== null ? (int) $offer->quantity : 0;
    }

    /**
     * Позиция ведёт остаток ЛОКАЛЬНО и списывает его при заказе: только
     * stock_mode=fixed (в normal остаток синхронизирует 1С, в ignore он не
     * учитывается). Один предикат для резерва и возврата — при разных
     * условиях остаток утекал бы на каждой отмене.
     */
    public function writesStockLocally(Product $product): bool
    {
        return $product->stock_mode === 'fixed';
    }

    /**
     * Явно назначить офферу роль контекста цены/остатка карточки: после этого
     * Product::getEffectivePrice()/getOriginalPrice()/isStockIgnored() считают
     * по магазину покупки ({@see Product::storeContext()}), как и каталожная
     * выдача. Без оффера ставим пустую связь — карточка сознательно остаётся
     * на своих колонках.
     */
    public function attachOffer(Product $product, ?StoreProduct $offer): void
    {
        $product->setRelation(
            'storeProducts',
            new EloquentCollection($offer !== null ? [$offer] : [])
        );
    }

    /**
     * Навесить каждой позиции корзины оффер ЕЁ магазина покупки.
     *
     * Продукт клонируем: eager load отдаёт один инстанс Product всем позициям
     * с этим product_id, а в корзине один и тот же товар может быть куплен
     * в разных магазинах — общий инстанс получил бы чужой оффер.
     *
     * @param  iterable<int, CartItem>  $items
     */
    public function attachToItems(iterable $items): void
    {
        if (! $this->isNetworkMode()) {
            return;
        }

        $items = collect($items)->filter(
            fn ($item) => $item instanceof CartItem
                && $item->relationLoaded('product')
                && $item->product instanceof Product
        );

        if ($items->isEmpty()) {
            return;
        }

        // Один запрос на всю корзину: офферы всех магазинов всех позиций.
        $offers = $this->offerMap(
            $items->pluck('store_id')->filter()->unique()->values()->all(),
            $items->pluck('product_id')->filter()->unique()->values()->all(),
        );

        foreach ($items as $item) {
            $product = clone $item->product;
            $item->setRelation('product', $product);

            $this->attachOffer(
                $product,
                $offers->get($item->store_id.':'.$item->product_id)
            );
        }
    }

    /**
     * Навесить офферы магазина товарам-добавкам (каталожные SupplementValue):
     * их цена и остаток тоже живут в store_products, а карточка добавки
     * глобальная и нулевая.
     *
     * @param  iterable<int, \App\Models\SupplementValue>  $values
     */
    public function attachToSupplementValues(iterable $values, int $storeId): void
    {
        if (! $this->isNetworkMode() || $storeId <= 0) {
            return;
        }

        $products = collect($values)
            ->filter(fn ($value) => $value instanceof SupplementValue && $value->product instanceof Product)
            ->map(fn (SupplementValue $value) => $value->product)
            ->values();

        if ($products->isEmpty()) {
            return;
        }

        $offers = $this->offerMap([$storeId], $products->pluck('id')->unique()->values()->all());

        foreach ($products as $product) {
            $this->attachOffer($product, $offers->get($storeId.':'.$product->id));
        }
    }

    /**
     * Валидные офферы пачкой: ключ «store_id:product_id».
     *
     * Публичный, чтобы вызывающая сторона могла сама решить, что делать с
     * результатом, не мутируя переданные позиции/товары — в отличие от
     * attachToItems(), который заменяет relation 'product' у каждой позиции
     * на клон. {@see \App\Services\Pricing\CartItemTierPricing::preloadOffers()}
     * (MID-308): оригинал товара нужен резерву и проверке остатка дальше по
     * CreateOrderAction, поэтому там клонируют сами и только для котировки.
     *
     * @param  array<int, int|string>  $storeIds
     * @param  array<int, int|string>  $productIds
     * @return SupportCollection<string, StoreProduct>
     */
    public function offerMap(array $storeIds, array $productIds): SupportCollection
    {
        if ($storeIds === [] || $productIds === []) {
            return collect();
        }

        return StoreProduct::query()
            ->whereIn('store_id', $storeIds)
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->with('store')
            ->get()
            ->keyBy(fn (StoreProduct $offer) => $offer->store_id.':'.$offer->product_id);
    }

    /**
     * Навесить офферы всем позициям корзины (включая дочерние — товарные
     * добавки: они тоже покупаются в магазине родителя) и товарам-добавкам
     * в выдаче позиции.
     */
    public function attachToCart(?Cart $cart): void
    {
        if ($cart === null || ! $this->isNetworkMode() || ! $cart->relationLoaded('items')) {
            return;
        }

        $this->attachToItems($cart->items);

        foreach ($cart->items as $item) {
            if ($item->relationLoaded('childItems')) {
                $this->attachToItems($item->childItems);
            }
        }

        $this->attachSupplementChains($cart->items);
    }

    /**
     * Навесить офферы одной позиции вне коллекции корзины — её карточке,
     * дочерним позициям и цепочкам добавок — по магазину ЕЁ покупки. То же,
     * что {@see self::attachToCart()}, но для позиции, которую нужно отдать
     * в форме CartItem отдельно от корзины (например, удаляемой при
     * переключении магазина, {@see \App\Actions\Cart\SwitchCartStoreAction}).
     */
    public function attachToItem(CartItem $item): void
    {
        if (! $this->isNetworkMode()) {
            return;
        }

        $this->attachToItems([$item]);

        if ($item->relationLoaded('childItems')) {
            $this->attachToItems($item->childItems);
        }

        $this->attachSupplementChains([$item]);
    }

    /**
     * Офферы товарам-добавкам во всех точках выдачи позиции:
     * - `childItems.sourceSupplementValue` — ВЫБРАННЫЕ добавки, именно из них
     *   {@see \App\Http\Resources\Api\V1\CartItemResource::foldedSupplements()}
     *   рендерит `supplements[]`;
     * - `supplements.values` — значения виртуальных наборов позиции;
     * - `product.supplements.values` — каталог доступных добавок карточки.
     *
     * Значения и их карточки КЛОНИРУЮТСЯ: eager load отдаёт один инстанс
     * SupplementValue/Product всем позициям корзины, поэтому без изоляции
     * позиция из магазина B перетирала бы офферы позиции из магазина A.
     *
     * @param  iterable<int, CartItem>  $items
     */
    private function attachSupplementChains(iterable $items): void
    {
        $items = collect($items)->filter(fn ($item) => $item instanceof CartItem);

        if ($items->isEmpty()) {
            return;
        }

        $storeIds = [];
        $productIds = [];

        // Первый проход: догружаем карточки добавок и собираем пары для запроса.
        foreach ($items as $item) {
            foreach ($this->supplementValueSources($item) as [$storeId, $values]) {
                foreach ($values as $value) {
                    if ($value->product instanceof Product) {
                        $storeIds[$storeId] = $storeId;
                        $productIds[$value->product->id] = $value->product->id;
                    }
                }
            }
        }

        $offers = $this->offerMap(array_values($storeIds), array_values($productIds));

        // Второй проход: изолируем значения и вешаем офферы магазина позиции.
        foreach ($items as $item) {
            $storeId = (int) $item->store_id;

            if ($item->relationLoaded('supplements')) {
                foreach ($item->supplements as $cartItemSupplement) {
                    if ($cartItemSupplement->relationLoaded('values')) {
                        $cartItemSupplement->setRelation(
                            'values',
                            $this->isolatedValues($cartItemSupplement->values, $storeId, $offers)
                        );
                    }
                }
            }

            if ($item->relationLoaded('childItems')) {
                foreach ($item->childItems as $child) {
                    $value = $child->relationLoaded('sourceSupplementValue')
                        ? $child->sourceSupplementValue
                        : null;

                    if ($value instanceof SupplementValue) {
                        $child->setRelation(
                            'sourceSupplementValue',
                            $this->isolatedValues([$value], (int) $child->store_id, $offers)->first()
                        );
                    }
                }
            }

            $product = $item->relationLoaded('product') ? $item->product : null;

            if ($product instanceof Product && $product->relationLoaded('supplements')) {
                // Карточка позиции уже клонирована attachToItems(), но набор
                // supplements у клонов общий — клонируем и его.
                $product->setRelation('supplements', new EloquentCollection(
                    $product->supplements->map(function ($supplement) use ($storeId, $offers) {
                        $copy = clone $supplement;

                        if ($copy->relationLoaded('values')) {
                            $copy->setRelation('values', $this->isolatedValues($copy->values, $storeId, $offers));
                        }

                        return $copy;
                    })->all()
                ));
            }
        }
    }

    /**
     * Источники значений добавок позиции: пары [магазин, значения].
     * Попутно догружает карточки добавок — без них вешать оффер не на что.
     *
     * @return array<int, array{0: int, 1: iterable<int, SupplementValue>}>
     */
    private function supplementValueSources(CartItem $item): array
    {
        $sources = [];

        if ($item->relationLoaded('supplements')) {
            $item->supplements->loadMissing('values.product');
            $sources[] = [
                (int) $item->store_id,
                $item->supplements->flatMap(fn ($cis) => $cis->relationLoaded('values') ? $cis->values : []),
            ];
        }

        if ($item->relationLoaded('childItems')) {
            $item->childItems->loadMissing('sourceSupplementValue.product');

            foreach ($item->childItems as $child) {
                $value = $child->relationLoaded('sourceSupplementValue') ? $child->sourceSupplementValue : null;

                if ($value instanceof SupplementValue) {
                    $sources[] = [(int) $child->store_id, [$value]];
                }
            }
        }

        $product = $item->relationLoaded('product') ? $item->product : null;

        if ($product instanceof Product && $product->relationLoaded('supplements')) {
            $product->supplements->loadMissing('values.product');
            $sources[] = [
                (int) $item->store_id,
                $product->supplements->flatMap(
                    fn ($supplement) => $supplement->relationLoaded('values') ? $supplement->values : []
                ),
            ];
        }

        return $sources;
    }

    /**
     * Копии значений добавок с офферами магазина: и значение, и его карточка
     * клонируются, чтобы соседняя позиция корзины (другой магазин) не получила
     * чужой оффер через общий инстанс.
     *
     * @param  iterable<int, SupplementValue>  $values
     * @param  SupportCollection<string, StoreProduct>  $offers
     * @return EloquentCollection<int, SupplementValue>
     */
    private function isolatedValues(iterable $values, int $storeId, SupportCollection $offers): EloquentCollection
    {
        return new EloquentCollection(collect($values)->map(function (SupplementValue $value) use ($storeId, $offers) {
            $copy = clone $value;
            $product = $copy->product;

            if ($product instanceof Product) {
                $productCopy = clone $product;
                $copy->setRelation('product', $productCopy);
                $this->attachOffer($productCopy, $offers->get($storeId.':'.$productCopy->id));
            }

            return $copy;
        })->all());
    }
}
