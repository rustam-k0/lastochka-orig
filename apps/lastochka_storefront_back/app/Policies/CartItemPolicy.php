<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;

final class CartItemPolicy
{
    /**
     * Может ли пользователь обновить элемент корзины.
     */
    public function update(User $user, CartItem $cartItem): bool
    {
        return $cartItem->cart->user_id === $user->id;
    }

    /**
     * Может ли пользователь удалить элемент корзины.
     */
    public function delete(User $user, CartItem $cartItem): bool
    {
        return $cartItem->cart->user_id === $user->id;
    }
}
