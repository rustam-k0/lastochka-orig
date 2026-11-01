<?php

namespace App\Services;

use App\Models\City;
use App\Support\AddressCityParser;

/**
 * Город магазина по данным внешнего события (shop.created / shop.updated).
 *
 * 1С шлёт адрес строкой и, как правило, без city_name — город выделяется из адреса.
 * Неизвестный город создаётся: справочник городов ведёт курьерка, витрина получает
 * его через штатное событие city.created (outbox-observer на City).
 */
class CityResolver
{
    public function resolveByNameOrAddress(?string $cityName, ?string $address): ?City
    {
        $name = AddressCityParser::normalizeCityName($cityName) ?? AddressCityParser::parse($address);

        if ($name === null) {
            return null;
        }

        return $this->findOrCreate($name);
    }

    public function findOrCreate(string $name): City
    {
        $name = mb_substr($name, 0, 100);

        $city = City::query()->where('name', $name)->orderBy('id')->first();
        if ($city !== null) {
            return $city;
        }

        // Удалённый в админке город с тем же именем восстанавливаем, а не дублируем.
        $trashed = City::onlyTrashed()->where('name', $name)->orderBy('id')->first();
        if ($trashed !== null) {
            $trashed->restore();

            return $trashed;
        }

        return City::create([
            'name' => $name,
            'is_active' => true,
        ]);
    }
}
