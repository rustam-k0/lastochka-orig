<?php

declare(strict_types=1);

namespace App\Actions\Order\Concerns;

/**
 * Общий предикат «позиция-добавка не участвует в учёте остатка» для
 * резервирования и возврата.
 *
 * Товарная добавка может явно снимать учёт остатка (`track_stock = false`
 * у SupplementValue). Резерв такие позиции пропускает; если бы возврат их
 * не пропускал, остаток раздувался бы на каждой отмене заказа — поэтому
 * предикат обязан быть ОДИН на обе стороны.
 */
trait TracksSupplementStock
{
    /**
     * @param  \App\Models\CartItem|\App\Models\OrderItem  $item
     */
    private function supplementStockUntracked($item): bool
    {
        if ($item->parent_id === null || $item->source_supplement_value_id === null) {
            return false;
        }

        $supplementValue = $item->sourceSupplementValue;

        return $supplementValue !== null && ! $supplementValue->tracksStock();
    }
}
