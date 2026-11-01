<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\User;
use App\Services\ProductGroup\ProductViewRecorder;

final class ClearCartAction
{
    public function __construct(
        private readonly ProductViewRecorder $productViewRecorder,
    ) {}

    /**
     * Очистить всю корзину.
     */
    public function execute(User $user): void
    {
        $cart = Cart::where('user_id', $user->id)->first();

        if ($cart) {
            // «Клал в корзину и убрал» — только позиции, выбранные самим
            // покупателем (дочерние товарные добавки едут за родителем).
            $productIds = $cart->items()->whereNull('parent_id')->pluck('product_id')->all();

            $cart->items()->delete();
            $this->productViewRecorder->markCartRemoved((int) $user->id, $productIds);
            $cart->promocodes()->delete();
        }
    }
}
