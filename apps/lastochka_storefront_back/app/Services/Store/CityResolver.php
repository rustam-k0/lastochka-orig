<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\City;
use App\Support\AddressCityParser;

/**
 * Город магазина по данным события shop.created / shop.updated.
 *
 * 1С публикует магазины напрямую в RabbitMQ с адресом строкой и, как правило,
 * без city_name — город выделяется из адреса. Неизвестный город создаётся
 * локально: витрина потребляет shop-события параллельно с курьеркой и не может
 * ждать city.created от неё; когда оно придёт, CityEventTransformer свяжет
 * запись по имени (findExistingEntity), дубля не будет.
 */
final class CityResolver
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

        // Удалённый город с тем же именем восстанавливаем, а не дублируем.
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
