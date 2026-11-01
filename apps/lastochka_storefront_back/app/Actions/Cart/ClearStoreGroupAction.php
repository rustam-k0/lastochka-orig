<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\User;

final class ClearStoreGroupAction
{
    /**
     * Очистить корзину для конкретного магазина.
     */
    public function execute(User $user, int $storeId): ?Cart
    {
        $cart = Cart::where('user_id', $user->id)->first();

        if (! $cart) {
            return null;
        }

        $cart->items()->where('store_id', $storeId)->delete();
        $cart->promocodes()->where('store_id', $storeId)->delete();

        return $cart->fresh(Cart::RESPONSE_RELATIONS);
    }
}
