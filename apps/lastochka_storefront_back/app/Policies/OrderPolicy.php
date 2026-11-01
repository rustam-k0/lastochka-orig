<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    /**
     * Просмотр заказа.
     */
    public function view(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }

    /**
     * Отмена заказа.
     */
    public function cancel(User $user, Order $order): bool
    {
        return $user->id === $order->user_id && $order->status->canCancel();
    }

    /**
     * Дополнение заказа (append).
     */
    public function append(User $user, Order $order): bool
    {
        return $user->id === $order->user_id
            && $order->status->canSupplement()
            && ! $order->isDeliverySlotExpired();
    }

    /**
     * Дополнение заказа (supplement).
     *
     * Помимо статуса заказа проверяем, что время слота доставки ещё не
     * истекло — иначе клиент может дополнить заказ, который уже давно
     * должен быть доставлен (ALD-130).
     */
    public function supplement(User $user, Order $order): bool
    {
        return $user->id === $order->user_id
            && $order->status->canSupplement()
            && ! $order->isDeliverySlotExpired();
    }
}
