<?php

declare(strict_types=1);

namespace App\Actions\Address;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateAddressAction
{
    /**
     * Создаёт адрес для пользователя.
     *
     * Новый адрес всегда становится активным: со всех прочих адресов
     * пользователя флаг is_active снимается в той же транзакции.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data) {
            Address::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $address = new Address($data);
            $address->user_id = $user->id;
            $address->full_address = $address->buildFullAddress();
            $address->is_active = true;
            $address->save();

            return $address;
        });
    }
}
