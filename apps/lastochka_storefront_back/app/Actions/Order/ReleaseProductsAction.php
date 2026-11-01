<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Actions\Order\Concerns\TracksSupplementStock;
use App\Models\Order;
use App\Models\Product;
use App\Services\Cart\CartStoreOffers;
use Illuminate\Support\Facades\DB;

final class ReleaseProductsAction
{
    use TracksSupplementStock;

    public function __construct(
        private readonly CartStoreOffers $storeOffers,
    ) {
    }

    /**
     * Возвращает зарезервированные товары на склад.
     * Использует lockForUpdate для избежания race condition.
     *
     * Сетевой каталог: склад позиции — оффер магазина заказа, колонку
     * глобальной карточки не трогаем (она всегда нулевая). Возврат делаем
     * ровно по тому же предикату, по которому резерв списывал
     * ({@see CartStoreOffers::writesStockLocally()}): при разных условиях
     * остаток утекал бы на каждой отмене. Карточку в сетевом режиме не лочим —
     * в неё ничего не пишется, а лок сериализовал бы отмены по всей сети.
     *
     * Пропуск позиций-добавок со снятым учётом остатка
     * ({@see TracksSupplementStock::supplementStockUntracked()}) применяется ДО
     * ветвления, то есть меняет и легаси-поведение. Это осознанно: пропуск в
     * резервировании появился в 402232d5, а симметрии в возврате не получил —
     * из-за чего track_stock=false раздувал остаток на каждой отмене
     * (никогда не списывали, но возвращали). Pre-existing баг легаси, чиним
     * вместе с сетевым режимом; всё остальное в легаси-ветке — как было.
     */
    public function execute(Order $order): void
    {
        $network = $this->storeOffers->isNetworkMode();

        DB::transaction(function () use ($order, $network) {
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                // Позиция-добавка со снятым учётом остатка не резервировалась
                // ({@see ReserveProductsAction}) — возвращать нечего, иначе
                // остаток раздувается на каждой отмене.
                if ($this->supplementStockUntracked($item)) {
                    continue;
                }

                $product = $network
                    ? Product::find($item->product_id)
                    : Product::lockForUpdate()->find($item->product_id);

                if (! $product) {
                    continue;
                }

                if ($network) {
                    if (! $this->storeOffers->writesStockLocally($product)) {
                        continue;
                    }

                    $offer = $this->storeOffers->offerFor(
                        (int) $item->product_id,
                        (int) $order->store_id,
                        lockForUpdate: true,
                    );

                    $offer?->increment('quantity', $item->quantity);

                    continue;
                }

                // Магазин в режиме ignore_stock не ведёт остатки —
                // не возвращаем количество, чтобы не раздувать синкнутый quantity.
                if (! $product->isStockIgnored()) {
                    $product->increment('quantity', $item->quantity);
                }
            }
        });
    }
}
