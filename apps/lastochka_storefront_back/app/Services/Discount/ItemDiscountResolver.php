<?php

declare(strict_types=1);

namespace App\Services\Discount;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StoreProduct;
use App\Services\Pricing\OrderItemPortions;
use App\Services\Pricing\PriceTierSnapshot;
use App\Services\Pricing\ProductPriceResolver;
use App\Services\Settings\ModuleConfigService;

/**
 * Единый ответ на вопрос «у позиции есть скидка» для промокодов и бонусов.
 *
 * Бизнес-правило: промокод НЕ суммируется со скидками, eligible-сумма
 * считается только по позициям без скидки; бонусы начисляются по тем же
 * позициям. Источник ответа обязан быть ОДИН для корзины и заказа — иначе
 * витрина и чек расходятся.
 *
 * Легаси: скидку определяет карточка ({@see Product::hasDiscount()} —
 * products.price_old плюс активные акции). Поведение прежнее байт-в-байт.
 *
 * Сетевой каталог: карточка глобальная, её price_old всегда NULL, а скидка
 * магазина живёт в store_products.price_old (1С кладёт туда реквизит
 * «ЦенаЗаЕдиницуСоСкидкой»). Поэтому скидку определяет СНАПШОТ позиции
 * (cart_items.price_old / order_items.price_old) плюс активные акции
 * карточки — как в легаси.
 *
 * Почему снапшот, а не живой оффер: снапшот пишется из оффера магазина
 * покупки при добавлении в корзину ({@see \App\Actions\Cart\AddToCartAction})
 * и обновляется на каждой выдаче корзины
 * ({@see \App\Http\Controllers\Api\V1\CartController::syncPrices()}), а
 * пересчёты заказа (CreateOrderAction, SupplementOrderAction,
 * OrderEventTransformer) офферы не подгружают вовсе. По живому офферу чек
 * разъезжался бы с корзиной при смене цены между добавлением и оформлением,
 * и цена позиции (снапшот) считалась бы по одному источнику, а её скидка —
 * по другому.
 *
 * Цены от количества (MID-308): если к позиции применена ступень
 * ({@see self::tierApplied()}), скидочность определяется ТОЛЬКО признаком
 * котировки/снапшота (`price_old !== null`) — акция на товаре не
 * учитывается, ступень приоритетнее акции. Для позиции заказа это правило
 * работает по снапшоту `order_items.price_tiers` независимо от локального
 * флага модуля (снапшот уже создан событием, «в полёте» заказ обязан
 * досчитываться по нему и после выключения флага).
 */
final class ItemDiscountResolver
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
        private readonly ProductPriceResolver $priceResolver,
    ) {}

    /**
     * Есть ли скидка у позиции корзины или заказа.
     *
     * Позиция без карточки скидки не имеет; null-обработка остаётся на
     * вызывающей стороне — она различается по местам вызова (где-то товар
     * без карточки не eligible, где-то eligible).
     *
     * Приоритет ступени (MID-308): если к позиции применена ступень «цены от
     * количества», скидочность определяется ТОЛЬКО признаком price_old
     * котировки (в корзине) или снапшота (в заказе) — акция на товаре при
     * этом не учитывается. См. {@see self::tierApplied()}.
     */
    public function hasDiscount(CartItem|OrderItem $item): bool
    {
        // Цены от количества (MID-308): к позиции применена ступень: скидочность
        // определяется только снапшотом price_old (он заполнен, когда ступень
        // дешевле опорной цены). Акция на товаре не учитывается: к позиции она
        // не применилась, и клиент на первой ступени терял бы промокод и бонусы.
        if ($this->tierApplied($item)) {
            return $item->price_old !== null;
        }

        $product = $item->product;

        if (! $this->moduleConfig->isNetworkCatalogEnabled()) {
            return $product !== null && $product->hasDiscount();
        }

        return $this->hasSnapshotDiscount($item->price_old, $product);
    }

    /**
     * Есть ли скидка у товара с известным снапшотом цены до скидки.
     * Для позиций, которых ещё нет в БД (создаются из входящего события
     * сборки: замена/дозаказ) — снапшот там резолвится
     * {@see self::offerPriceOld()}.
     */
    public function hasDiscountForProduct(Product $product, ?float $priceOld): bool
    {
        if (! $this->moduleConfig->isNetworkCatalogEnabled()) {
            return $product->hasDiscount();
        }

        return $this->hasSnapshotDiscount($priceOld, $product);
    }

    /**
     * Цена до скидки в офере магазина — снапшот для позиции, которой ещё нет
     * (замена/дозаказ от сборщика приходит событием, корзины за ней нет).
     * В легаси всегда null: там скидку определяет карточка, и колонка
     * price_old таких позиций как была пустой, так и остаётся.
     */
    public function offerPriceOld(?Product $product, ?int $storeId): ?float
    {
        if ($product === null || $storeId === null || $storeId <= 0) {
            return null;
        }

        if (! $this->moduleConfig->isNetworkCatalogEnabled()) {
            return null;
        }

        // Те же условия валидного оффера, что у корзины и каталожной выдачи
        // ({@see \App\Services\Cart\CartStoreOffers::offerFor()}): активная
        // строка с ценой. Запрос здесь редкий (только замены и дозаказы),
        // поэтому обходимся без внедрения сервиса корзины в консьюмер.
        $offer = StoreProduct::query()
            ->where('store_id', $storeId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->first();

        return $offer?->price_old !== null ? (float) $offer->price_old : null;
    }

    /**
     * Применена ли к позиции ступень «цены от количества» (MID-308).
     *
     * Позиция заказа: по её снапшоту лесенки и текущему количеству, без
     * обращения к живым ценам и без чтения флага (зависит только от наличия
     * снапшота у позиции). Позиция корзины: через резолвер цены по лесенке
     * магазина позиции, только при включённом модуле. Дочерние позиции
     * (товарные добавки) ступенями не затрагиваются.
     */
    private function tierApplied(CartItem|OrderItem $item): bool
    {
        if ($item->parent_id !== null) {
            return false;
        }

        if ($item instanceof OrderItem) {
            // Только наличие снапшота: локальный флаг здесь не читается. Снапшот
            // появляется у позиции лишь при включённом модуле, а заказ «в полёте»
            // обязан досчитываться по нему и после выключения флага.
            if ($item->price_tiers === null) {
                return false;
            }

            $snapshot = PriceTierSnapshot::fromArray($item->price_tiers);

            return $snapshot !== null && $snapshot->quote(OrderItemPortions::of($item))->tierApplied;
        }

        if (! $item->product instanceof Product || ! $this->moduleConfig->isPriceTiersEnabled()) {
            return false;
        }

        $storeId = $this->moduleConfig->isNetworkCatalogEnabled() ? (int) $item->store_id : 0;

        return $this->priceResolver->isTierApplied(
            $item->product,
            (float) $item->portions,
            $storeId > 0 ? $storeId : null
        );
    }

    /**
     * Сетевое правило: скидка позиции — это снапшот цены до скидки (оффер
     * магазина покупки) либо активная акция карточки. Колонку глобальной
     * карточки products.price_old здесь сознательно не читаем: в сетевом
     * режиме она не является ценой магазина.
     */
    private function hasSnapshotDiscount(?float $priceOld, ?Product $product): bool
    {
        if ($priceOld !== null) {
            return true;
        }

        return $product !== null && $product->hasActivePromotion();
    }
}
