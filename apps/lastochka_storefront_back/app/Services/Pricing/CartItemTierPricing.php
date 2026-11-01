<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\StoreProduct;
use App\Services\Cart\CartStoreOffers;
use App\Services\Discount\ItemDiscountResolver;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Support\Collection;

/**
 * Котировка позиций корзины при оформлении и дозаказе (цены от количества, MID-308).
 *
 * CreateOrderAction и SupplementOrderAction считают суммы по снапшоту цены
 * корзины и офферы магазина не подгружают. Для позиций с лесенкой цена обязана
 * совпадать со снапшотом лесенки, который уезжает в заказ, поэтому такие
 * позиции перекотируются здесь. Позиции без лесенки не трогаются.
 */
final class CartItemTierPricing
{
    /**
     * Пачечно предзагруженные офферы магазина на время текущего requote()
     * (ключ «store_id:product_id»); null-значение — оффера нет, чтобы
     * pricingProduct() не проваливался в повторный запрос при промахе кеша.
     *
     * @var array<string, StoreProduct|null>
     */
    private array $offerCache = [];

    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
        private readonly ProductPriceResolver $priceResolver,
        private readonly ItemDiscountResolver $itemDiscount,
        private readonly CartStoreOffers $storeOffers,
    ) {}

    public function enabled(): bool
    {
        return $this->moduleConfig->isPriceTiersEnabled();
    }

    /**
     * Перекотировать корневые позиции с лесенкой: price / price_old меняются
     * В ПАМЯТИ (в БД корзины их обновит ближайшая выдача GET /cart).
     *
     * @param  Collection<int, CartItem>  $cartItems
     * @return array<int, PriceTierSnapshot> cart_item_id => снапшот лесенки
     */
    public function requote(Collection $cartItems): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $this->priceResolver->preloadCartItems($cartItems);
        $this->preloadOffers($cartItems);

        $snapshots = [];

        foreach ($cartItems as $item) {
            $snapshot = $this->snapshotForCartItem($item);

            if ($snapshot === null) {
                continue;
            }

            $quote = $snapshot->quote((float) $item->portions);

            $item->price = $quote->unitPrice;
            $item->price_old = $quote->unitPriceOld;

            $snapshots[(int) $item->id] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * Снапшот лесенки корневой позиции корзины; null: флаг выключен, позиция
     * дочерняя, товара нет, лесенки в магазине позиции нет либо (сетевой
     * каталог) у товара нет валидного оффера в магазине позиции.
     */
    public function snapshotForCartItem(CartItem $item): ?PriceTierSnapshot
    {
        if (! $this->enabled() || $item->parent_id !== null) {
            return null;
        }

        $product = $item->product;

        if (! $product instanceof Product) {
            return null;
        }

        $network = $this->storeOffers->isNetworkMode();
        $storeId = $network ? (int) $item->store_id : (int) $product->store_id;

        // Сначала дешёвая проверка по предзагруженным лесенкам: без лесенки
        // оффер магазина не запрашиваем и позицию не трогаем.
        if ($this->priceResolver->portionLadder($product, $storeId) === []) {
            return null;
        }

        $pricingProduct = $this->pricingProduct($product, $storeId, $network);

        if ($pricingProduct === null) {
            return null;
        }

        $baseIsEligible = ! $this->itemDiscount->hasDiscountForProduct(
            $pricingProduct,
            $pricingProduct->getOriginalPrice()
        );

        return $this->priceResolver->snapshotFor($pricingProduct, $baseIsEligible);
    }

    /**
     * Товар с контекстом цены магазина позиции. В сетевом каталоге карточка
     * клонируется: оффер нужен только для котировки и не должен менять
     * поведение остального оформления (резерв, проверки остатка).
     */
    private function pricingProduct(Product $product, int $storeId, bool $network): ?Product
    {
        if (! $network) {
            return $product;
        }

        $context = $product->storeContext();

        if ($context !== null && (int) $context->store_id === $storeId) {
            return $product;
        }

        $key = $storeId.':'.$product->id;

        // Промах кеша (снапшот запрошен вне requote(), например точечно из
        // дозаказа): оффер по одной позиции, как и раньше.
        $offer = array_key_exists($key, $this->offerCache)
            ? $this->offerCache[$key]
            : $this->storeOffers->offerFor((int) $product->id, $storeId);

        if ($offer === null) {
            return null;
        }

        $clone = clone $product;
        $this->storeOffers->attachOffer($clone, $offer);

        return $clone;
    }

    /**
     * Пачечно предзагрузить офферы магазина корневых позиций, у которых
     * товар уже имеет лесенку в магазине позиции: без этого pricingProduct()
     * дёргал store_products по запросу на позицию (N+1) при оформлении
     * заказа с несколькими тарифицируемыми товарами в сетевом каталоге
     * (ревью фикс-раунд 1, MID-308). Позиции без лесенки и позиции, у
     * которых оффер уже загружен как storeContext(), в выборку не входят —
     * им запрос не нужен ни здесь, ни в pricingProduct().
     *
     * Запрошенные пары кэшируются значением null ДО чтения ответа
     * offerMap(): она отфильтровывает невалидные строки (is_active=false,
     * price=0), и без предварительного null пара «лесенка есть, оффера
     * нет» осталась бы вне кеша — pricingProduct() при промахе падал бы
     * обратно на поштучный offerFor(), возвращая N+1 именно для таких
     * позиций (ревью фикс-раунд 2, MID-308).
     *
     * @param  Collection<int, CartItem>  $cartItems
     */
    private function preloadOffers(Collection $cartItems): void
    {
        $this->offerCache = [];

        if (! $this->storeOffers->isNetworkMode()) {
            return;
        }

        $storeIds = [];
        $productIds = [];
        $requestedKeys = [];

        foreach ($cartItems as $item) {
            if ($item->parent_id !== null) {
                continue;
            }

            $product = $item->product;

            if (! $product instanceof Product) {
                continue;
            }

            $storeId = (int) $item->store_id;
            $context = $product->storeContext();

            if ($context !== null && (int) $context->store_id === $storeId) {
                continue;
            }

            if ($this->priceResolver->portionLadder($product, $storeId) === []) {
                continue;
            }

            $storeIds[$storeId] = $storeId;
            $productIds[(int) $product->id] = (int) $product->id;
            $requestedKeys[$storeId.':'.(int) $product->id] = true;
        }

        if ($storeIds === [] || $productIds === []) {
            return;
        }

        foreach (array_keys($requestedKeys) as $key) {
            $this->offerCache[$key] = null;
        }

        foreach ($this->storeOffers->offerMap(array_values($storeIds), array_values($productIds)) as $key => $offer) {
            $this->offerCache[$key] = $offer;
        }
    }
}
