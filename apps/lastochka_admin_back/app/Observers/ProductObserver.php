<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;

class ProductObserver
{
    public function created(Product $product): void
    {
        if ($product->is_configurable && empty($product->articul)) {
            $product->withoutEvents(function () use ($product) {
                $product->articul = 'CONF-' . $product->id;
                $product->save();
            });
        }
    }

    /**
     * Запрещаем менять parent_id у существующего variant'а через UpdateProduct.
     * Смена parent_id разрешена только через ConfigurableProductDetach/ConfigurableProductCreate.
     */
    public function updating(Product $product): void
    {
        if ($product->exists && $product->isDirty('parent_id')) {
            $original = $product->getOriginal('parent_id');
            $new = $product->parent_id;

            if ($original !== null && $new !== null && (int) $original !== (int) $new) {
                throw new \DomainException(
                    "Нельзя сменить parent_id у variant'а #{$product->id} через обычное обновление."
                    . ' Используй Detach + привязку заново.'
                );
            }
        }
    }
}
