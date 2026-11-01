<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\User;

final class OrderReviewPolicy
{
    /**
     * Может ли пользователь просматривать отзыв.
     */
    public function view(User $user, OrderReview $orderReview): bool
    {
        return $orderReview->user_id === $user->id;
    }

    /**
     * Может ли пользователь создать отзыв на заказ.
     */
    public function createForOrder(User $user, Order $order): bool
    {
        // Заказ должен принадлежать пользователю
        if ($order->user_id !== $user->id) {
            return false;
        }

        // Заказ должен быть доставлен
        if ($order->status !== OrderStatus::Delivered) {
            return false;
        }

        // Проверку дубликата делаем в Action для правильной 422 ошибки
        return true;
    }

    /**
     * Может ли пользователь обновить отзыв.
     */
    public function update(User $user, OrderReview $orderReview): bool
    {
        return $orderReview->user_id === $user->id;
    }
}
