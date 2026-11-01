<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

final class AddressPolicy
{
    /**
     * Просмотр адреса.
     */
    public function view(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    /**
     * Обновление адреса.
     */
    public function update(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    /**
     * Удаление адреса.
     */
    public function delete(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }

    /**
     * Установка адреса как активного.
     */
    public function setActive(User $user, Address $address): bool
    {
        return $user->id === $address->user_id;
    }
}
