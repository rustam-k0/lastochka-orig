<?php

declare(strict_types=1);

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\User;

final class RemovePromocodeAction
{
    /**
     * Удалить промокод. Если $storeId=null — удаляется platform-wide
     * промокод (cart_promocodes.store_id is null), иначе — для конкретного
     * магазина.
     */
    public function execute(User $user, ?int $storeId): ?Cart
    {
        $cart = Cart::where('user_id', $user->id)->first();

        if (! $cart) {
            return null;
        }

        if ($storeId === null) {
            $cart->promocodes()->whereNull('store_id')->delete();
        } else {
            $cart->promocodes()->where('store_id', $storeId)->delete();
        }

        return $cart->fresh(Cart::RESPONSE_RELATIONS);
    }
}
