<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\ProductGroup\ProductViewRecorder;

final class RemoveCartItemAction
{
    public function __construct(
        private readonly ProductViewRecorder $productViewRecorder,
    ) {}

    /**
     * Удалить товар из корзины.
     */
    public function execute(CartItem $cartItem): Cart
    {
        $cart = $cartItem->cart;
        $storeId = $cartItem->store_id;

        $cartItem->delete();

        // «Клал в корзину и убрал» — сигнал ранжирования «Смотрел, но не купил».
        $this->productViewRecorder->markCartRemoved((int) $cart->user_id, [$cartItem->product_id]);

        $hasItemsInStore = $cart->items()->where('store_id', $storeId)->exists();
        if (! $hasItemsInStore) {
            $cart->promocodes()->where('store_id', $storeId)->delete();
        }

        return $cart->fresh(Cart::RESPONSE_RELATIONS);
    }
}
