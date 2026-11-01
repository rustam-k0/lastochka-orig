<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Actions\Cart\Concerns\HandlesSupplements;
use App\Http\Resources\Api\V1\CartItemResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Cart\CartStoreOffers;
use App\Services\Pricing\ProductPriceResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Переключить корзину на магазин (MID-204): клиент сменил адрес доставки
 * или магазин самовывоза — позиции перепроверяются по ассортименту нового
 * магазина.
 *
 * Сетевой каталог: позиция с валидным оффером в целевом магазине
 * перепривязывается к нему (store_id + снапшот цены оффера), одинаковые
 * позиции сливаются, позиция без оффера или без остатка удаляется и
 * возвращается в removedItems в форме CartItem обычной корзины — из него
 * клиент рисует подсказку «Некоторых товаров нет в наличии» и затемнённые
 * карточки удалённых позиций. Товарные добавки едут вместе с родителем; нет
 * оффера у добавки — недоступна вся позиция.
 *
 * Легаси-режим: у товара один магазин-владелец, перепривязка невозможна,
 * а мультимагазинная корзина — норма маркетплейса. Ничего не меняем.
 */
final class SwitchCartStoreAction
{
    use HandlesSupplements;

    public function __construct(
        private readonly CartStoreOffers $storeOffers,
        private readonly ProductPriceResolver $priceResolver,
    ) {
    }

    /**
     * removedItems — удалённые позиции в форме CartItem обычной корзины
     * ({@see self::removedItemPayload()}).
     *
     * @return array{cart: Cart|null, storeId: int, removedItems: list<array<string, mixed>>}
     *
     * @throws ValidationException магазин не определился (нет storeId и активного адреса)
     */
    public function execute(User $user, ?int $storeId): array
    {
        $cart = Cart::query()->where('user_id', $user->id)->first();

        if (! $this->storeOffers->isNetworkMode()) {
            return [
                'cart' => $cart?->fresh(Cart::RESPONSE_RELATIONS),
                'storeId' => (int) $storeId,
                'removedItems' => [],
            ];
        }

        $targetStoreId = $this->storeOffers->resolvePurchaseStoreId($storeId, $user);

        if ($targetStoreId === 0) {
            throw new ValidationException(
                validator: validator([], []),
                response: response()->json([
                    'message' => 'Не удалось определить магазин: передайте storeId или выберите адрес',
                    'errors' => ['storeId' => ['Не удалось определить магазин: передайте storeId или выберите адрес']],
                ], 422)
            );
        }

        if ($cart === null) {
            return ['cart' => null, 'storeId' => $targetStoreId, 'removedItems' => []];
        }

        $removedItems = DB::transaction(
            fn () => $this->switchItems($cart, $targetStoreId),
            attempts: 3, // retry при deadlock (SQLSTATE 40001) от lockForUpdate на cart_items
        );

        return [
            'cart' => $cart->fresh(Cart::RESPONSE_RELATIONS),
            'storeId' => $targetStoreId,
            'removedItems' => $removedItems,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function switchItems(Cart $cart, int $targetStoreId): array
    {
        $removedItems = [];

        /** @var EloquentCollection<int, CartItem> $roots */
        $roots = $cart->items()
            ->whereNull('parent_id')
            ->lockForUpdate()
            ->with(['product', 'supplements.values', 'childItems.sourceSupplementValue.product', 'childItems.product'])
            ->orderBy('id')
            ->get();

        // Позиции целевого магазина обрабатываются первыми: именно в них
        // сливаются одинаковые позиции из других магазинов.
        $ordered = $roots
            ->sortBy(fn (CartItem $item) => (int) $item->store_id === $targetStoreId ? 0 : 1)
            ->values();

        // Цены от количества (MID-308): лесенки текущих (до переключения)
        // магазинов позиций одним запросом — иначе каждая удаляемая позиция
        // (removedItemPayload() → CartItemResource → ProductPriceResolver)
        // тянет свою лесенку отдельным запросом.
        $this->priceResolver->preloadCartItems($ordered);

        // Цены от количества (MID-308, финальное ревью F2): лесенки ЦЕЛЕВОГО
        // магазина одним запросом — rebind() и mergeInto() ниже котируют по
        // ЦЕЛЕВОМУ магазину (оффер, навешенный resolveOffers()), а не по
        // текущему, который предзагружен строкой выше. Без этого каждая
        // перепривязанная или слитая позиция тянет лесенку отдельным
        // запросом. Список товаров сужен до тех, у кого в целевом магазине
        // ЕСТЬ валидный оффер (иначе позиция будет удалена, а не
        // перепривязана/слита — грузить её лесенку незачем): если валидных
        // офферов нет ни у одной позиции (все удаляются), preloadPairs()
        // не делает ни одного запроса к product_price_tiers.
        //
        // Остатки ревью (R3): гейт флага первым действием этого блока — при
        // выключенном модуле price_tiers offerMap() (лишний запрос к
        // store_products) вообще не вызывается, как preloadPairs() и
        // preloadCartItems() выше уже гейтят себя сами.
        if ($this->priceResolver->enabled()) {
            $targetOfferProductIds = $this->storeOffers
                ->offerMap([$targetStoreId], $ordered->pluck('product_id')->unique()->all())
                ->map(fn (StoreProduct $offer) => (int) $offer->product_id)
                ->values();
            $this->priceResolver->preloadPairs($targetStoreId, $targetOfferProductIds);
        }

        /** @var array<string, CartItem> $existing позиции целевого магазина по ключу «product_id/набор добавок» */
        $existing = [];

        foreach ($ordered as $item) {
            $offers = $this->resolveOffers($item, $targetStoreId);

            if ($offers === null) {
                $removedItems[] = $this->removedItemPayload($item);
                $item->delete();

                continue;
            }

            $key = $item->product_id.'/'.$this->buildSupplementsKeyFromCartItem($item);

            if ((int) $item->store_id === $targetStoreId) {
                // Уже в целевом магазине: цену пересчитает выдача (GET /cart).
                $existing[$key] = $item;

                continue;
            }

            if (isset($existing[$key])) {
                $this->mergeInto($existing[$key], $item);
                $item->delete();

                continue;
            }

            $this->rebind($item, $targetStoreId, $offers);
            $existing[$key] = $item;
        }

        // Промокоды магазинов, в которых не осталось позиций (platform-wide,
        // store_id=null, не трогаем — как RemoveCartItemAction).
        $remainingStoreIds = $cart->items()->distinct()->pluck('store_id')->all();
        $cart->promocodes()
            ->whereNotNull('store_id')
            ->whereNotIn('store_id', $remainingStoreIds)
            ->delete();

        return $removedItems;
    }

    /**
     * Удаляемая позиция для removedItems — в форме CartItem обычной корзины
     * (та же {@see CartItemResource}, что рисует позиции в GET /cart), чтобы
     * клиент отрисовал её той же карточкой, только затемнённой. Карточка —
     * в контексте магазина, в котором позиция ЛЕЖАЛА (цена и остаток его
     * оффера): ровно то, что клиент видел до переключения; оффер целевого
     * магазина тут ни при чём — его нет или он без остатка.
     *
     * productId/title — прежний контракт ручки, остаются рядом с полями
     * CartItem, чтобы не ломать клиентов, которые их уже разбирают.
     *
     * Снимается ДО delete(): дочерние позиции уходят каскадом.
     *
     * @return array<string, mixed>
     */
    private function removedItemPayload(CartItem $item): array
    {
        $item->loadMissing(CartItem::RESPONSE_RELATIONS);
        $this->storeOffers->attachToItem($item);

        return CartItemResource::make($item)->resolve() + [
            'productId' => (int) $item->product_id,
            'title' => (string) ($item->product?->title ?? ''),
        ];
    }

    /**
     * Офферы целевого магазина для позиции и её дочерних позиций.
     * null — позиция там недоступна: карточка неактивна, нет валидного
     * оффера, остаток нулевой (и магазин учитывает остатки) либо нет оффера
     * у какой-то товарной добавки.
     *
     * @return array{root: StoreProduct, children: array<int, StoreProduct>}|null
     */
    private function resolveOffers(CartItem $item, int $storeId): ?array
    {
        $product = $item->product;

        if (! $product instanceof Product || $product->trashed() || ! $product->is_active) {
            return null;
        }

        $offer = $this->storeOffers->offerFor((int) $product->id, $storeId);

        if ($offer === null) {
            return null;
        }

        $this->storeOffers->attachOffer($product, $offer);

        if ((int) $offer->quantity <= 0 && ! $product->isStockIgnored()) {
            return null;
        }

        $children = [];

        foreach ($item->childItems as $child) {
            $childProduct = $child->product;

            if (! $childProduct instanceof Product || $childProduct->trashed() || ! $childProduct->is_active) {
                return null;
            }

            $childOffer = $this->storeOffers->offerFor((int) $childProduct->id, $storeId);

            if ($childOffer === null) {
                return null;
            }

            $this->storeOffers->attachOffer($childProduct, $childOffer);

            $value = $child->sourceSupplementValue;
            if ($value !== null && $value->tracksStock() && (int) $childOffer->quantity <= 0 && ! $childProduct->isStockIgnored()) {
                return null;
            }

            $children[(int) $child->id] = $childOffer;
        }

        return ['root' => $offer, 'children' => $children];
    }

    /**
     * Перепривязать позицию и её дочерние к целевому магазину со снапшотом
     * цен его офферов (зеркало создания позиции в AddToCartAction /
     * HandlesSupplements::createCatalogChildren).
     *
     * @param  array{root: StoreProduct, children: array<int, StoreProduct>}  $offers
     */
    private function rebind(CartItem $item, int $storeId, array $offers): void
    {
        $product = $item->product;

        // Цены от количества (MID-308): оффер целевого магазина навешен в
        // resolveOffers(), лесенка берётся его же.
        $quote = $this->priceResolver->quote($product, (float) $item->portions);

        $item->update([
            'store_id' => $storeId,
            'price' => $quote->price,
            'price_old' => $quote->priceOld,
        ]);

        foreach ($item->childItems as $child) {
            $value = $child->sourceSupplementValue;
            $childProduct = $child->product;

            if ($value !== null) {
                // Оффер уже навешен в resolveOffers() на child->product — тот же
                // инстанс, что и value->product, только если связь общая; навешиваем
                // явно, чтобы effectivePrice() считал по офферу целевого магазина.
                if ($value->product instanceof Product) {
                    $this->storeOffers->attachOffer($value->product, $offers['children'][(int) $child->id] ?? null);
                }
                $price = $value->effectivePrice();
                // Скидка магазина есть только у добавки без собственной цены
                // (цена пришла из оффера) — как при создании дочерней позиции.
                $priceOld = $value->price === null && $value->product instanceof Product
                    ? $value->product->getOriginalPrice()
                    : null;
            } else {
                $price = $childProduct instanceof Product ? $childProduct->getEffectivePrice() : (float) $child->price;
                $priceOld = $childProduct instanceof Product ? $childProduct->getOriginalPrice() : null;
            }

            $child->update([
                'store_id' => $storeId,
                'price' => $price,
                'price_old' => $priceOld,
            ]);
        }
    }

    /**
     * Слить позицию $source в уже лежащую в целевом магазине $target:
     * количества суммируются, дочерние позиции $target пересчитываются
     * пропорционально порциям (как при повторном добавлении).
     */
    private function mergeInto(CartItem $target, CartItem $source): void
    {
        $oldPortions = max(1, $target->getPortionsAttribute());

        $target->update(['quantity' => $target->quantity + $source->quantity]);
        $newPortions = max(1, $target->fresh(['product'])->getPortionsAttribute());

        // Цены от количества (MID-308): слияние меняет количество, а значит и
        // ступень. Без модуля, и без лесенки у товара (финальное ревью F8:
        // раньше условие было только enabled(), поэтому слияние переписывало
        // цену и товарам БЕЗ лесенки — до фичи слияние цену не трогало),
        // цену позиции целевого магазина, как и раньше, пересчитывает выдача
        // GET /cart.
        if ($this->priceResolver->enabled()
            && $target->product instanceof Product
            && $this->priceResolver->portionLadder($target->product) !== []) {
            $quote = $this->priceResolver->quote($target->product, (float) $newPortions);
            $target->update(['price' => $quote->price, 'price_old' => $quote->priceOld]);
        }

        foreach ($target->childItems as $child) {
            $perUnit = (int) round($child->quantity / $oldPortions);
            $child->update(['quantity' => $perUnit * $newPortions]);
        }
    }
}
