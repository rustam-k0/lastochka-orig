<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Concerns\TracksSupplementStock;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Services\Cart\CartStoreOffers;
use Illuminate\Support\Collection;

final class ReserveProductsAction
{
    use TracksSupplementStock;

    public function __construct(
        private readonly CartStoreOffers $storeOffers,
    ) {
    }

    /**
     * Резервирует товары (уменьшает quantity) с блокировкой строк.
     *
     * Сетевой каталог: остаток ведётся в оффере магазина покупки позиции
     * (cart_items.store_id) — карточка сети всегда нулевая, и легаси-проверка
     * блокировала бы любой заказ.
     *
     * @param  Collection<int, \App\Models\CartItem>  $cartItems
     *
     * @throws InsufficientStockException
     */
    public function execute(Collection $cartItems): void
    {
        $network = $this->storeOffers->isNetworkMode();

        // Предзагружаем sourceSupplementValue с product для дочерних позиций,
        // чтобы определить track_stock override без N+1.
        // Используем Eloquent eager-loading напрямую на моделях с parent_id.
        $childItems = $cartItems->filter(fn ($item) => $item->parent_id !== null && $item->source_supplement_value_id !== null);
        if ($childItems->isNotEmpty()) {
            $childItems->each(fn ($item) => $item->loadMissing('sourceSupplementValue.product'));
        }

        foreach ($cartItems as $item) {
            // Дочерняя позиция (каталожная добавка) с явно снятым учётом
            // остатка (track_stock=false) не резервируется. Тот же предикат
            // пропуска обязан быть в ReleaseProductsAction — иначе возврат
            // раздувает остаток, который никто не списывал.
            if ($this->supplementStockUntracked($item)) {
                continue;
            }

            // В сетевом режиме карточка только читается (склад — в оффере),
            // поэтому её НЕ лочим: иначе все параллельные заказы одного SKU
            // по всей сети сериализовались бы на одной строке products.
            $product = $network
                ? Product::find($item->product_id)
                : Product::lockForUpdate()->find($item->product_id);

            if (! $product) {
                throw new InsufficientStockException(
                    productTitle: $item->product->title ?? 'Неизвестный товар',
                    available: 0,
                    requested: $item->quantity,
                );
            }

            // Сетевой режим: источник остатка — оффер магазина позиции. Он же
            // становится контекстом карточки, чтобы isStockIgnored() смотрел
            // на магазин ПОКУПКИ, а не на якорный магазин-владелец.
            $offer = null;

            if ($network) {
                $offer = $this->storeOffers->offerFor(
                    (int) $item->product_id,
                    (int) $item->store_id,
                    lockForUpdate: true,
                );

                $this->storeOffers->attachOffer($product, $offer);

                if ($offer === null && ! $product->isStockIgnored()) {
                    throw new InsufficientStockException(
                        productTitle: $product->title,
                        available: 0,
                        requested: $item->quantity,
                    );
                }
            }

            $available = $offer !== null ? (int) $offer->quantity : (int) $product->quantity;
            // Позиция корзины товара «по кг/л» — в кг/л, остаток — в г/мл.
            $requestedInStock = $product->cartQuantityInStockUnits((int) $item->quantity);

            if (! $product->isStockIgnored() && $available < $requestedInStock) {
                throw new InsufficientStockException(
                    productTitle: $product->title,
                    available: intdiv($available, $product->stockUnitsPerCartUnit()),
                    requested: $item->quantity,
                );
            }

            // Списываем дневной лимит только в режиме fixed: для него остаток ведётся
            // вручную (1С не управляет), а проверка quantity < item->quantity выше не
            // даёт уйти в минус. normal/ignore не декрементим — остатком управляют
            // 1С (normal) либо режим «не учитывать остатки» (ignore).
            // Предикат общий с ReleaseProductsAction — иначе остаток утекает
            // на отменах.
            if ($this->storeOffers->writesStockLocally($product)) {
                // В сетевом режиме склад позиции — оффер магазина покупки;
                // колонка глобальной карточки остаётся нулевой.
                if ($offer !== null) {
                    $offer->decrement('quantity', $requestedInStock);
                } elseif (! $network) {
                    $product->decrement('quantity', $requestedInStock);
                }
            }
        }
    }
}
