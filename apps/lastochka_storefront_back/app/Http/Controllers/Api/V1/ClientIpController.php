<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CityResource;
use App\Models\City;
use App\Services\Geo\Contracts\IpGeolocatorInterface;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('App', weight: 20)]
final class ClientIpController extends Controller
{
    /**
     * Текущий IP клиента и его город
     *
     * Возвращает внешний IP адрес, с которого пришёл запрос, и город
     * клиента, определённый по IP. Публичная ручка, авторизация не требуется.
     *
     * `city` заполняется только если город определён и в нём есть активные
     * магазины; иначе `city: null` (в том числе когда локацию определить
     * не удалось — приватный IP, сбой гео-провайдера).
     *
     * Если IP клиента геолоцирован за пределами РФ — 403.
     *
     * Приложение стоит за upstream-проксей, поэтому реальный адрес клиента
     * берётся из первого элемента X-Forwarded-For; без заголовка (локальное
     * окружение, прямой доступ) — из адреса соединения. Trusted proxies
     * глобально не настраиваются намеренно: изменение request()->ip()
     * задело бы IP-проверку вебхука YooKassa.
     *
     * @response array{data: array{ip: string, city: CityResource|null}}
     */
    public function show(Request $request, IpGeolocatorInterface $geolocator): JsonResponse
    {
        $ip = \App\Support\ClientIp::fromRequest($request);
        $location = $geolocator->locate($ip);

        // Неопределённая локация (null) — не повод для 403: приватный IP
        // или сбой провайдера не означают «клиент вне РФ».
        if ($location !== null && $location->countryCode !== 'RU') {
            abort(403, 'Сервис доступен только на территории Российской Федерации.');
        }

        $city = null;

        if ($location?->city !== null && $location?->city !== '') {
            // Матчинг по имени: города приходят от гео-провайдера на русском
            // (lang=ru) и в cities.name хранятся так же; регистр сглаживает
            // CI-collation MySQL.
            $city = City::query()
                ->active()
                ->where('name', $location->city)
                ->whereHas('stores', fn ($query) => $query->where('is_active', true))
                ->with('defaultStore')
                ->first();
        }

        return response()->json([
            'data' => [
                'ip' => $ip,
                'city' => $city !== null ? new CityResource($city) : null,
            ],
        ]);
    }
}
