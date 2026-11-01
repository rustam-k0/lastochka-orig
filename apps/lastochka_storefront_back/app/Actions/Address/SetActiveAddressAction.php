<?php

declare(strict_types=1);

namespace App\Actions\Address;

use App\Models\Address;
use Illuminate\Support\Facades\DB;

final class SetActiveAddressAction
{
    /**
     * Устанавливает адрес как активный.
     */
    public function execute(Address $address): Address
    {
        // attempts=3: конкурентные PUT /active одного пользователя дедлочатся
        // на bulk-UPDATE (SQLSTATE 40001) — Laravel повторит транзакцию сам
        return DB::transaction(function () use ($address) {
            // Снимаем is_active с остальных адресов пользователя. Сам адрес
            // исключён: если он уже активен, save() ниже ничего не пишет
            // (модель не dirty), и bulk-UPDATE оставил бы его выключенным.
            Address::query()
                ->where('user_id', $address->user_id)
                ->where('id', '!=', $address->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Устанавливаем is_active на выбранный адрес
            $address->is_active = true;
            $address->save();

            return $address;
        }, 3);
    }
}
