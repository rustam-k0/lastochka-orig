<?php

declare(strict_types=1);

namespace App\Services\Geo;

use App\DTOs\Geo\IpLocationData;
use App\Services\Geo\Contracts\IpGeolocatorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Геолокация IP через ip-api.com.
 *
 * Бесплатный тариф: без ключа, лимит 45 запросов/мин, только HTTP —
 * поэтому успешные ответы кешируются (TTL services.geoip.cache_ttl).
 * lang=ru — названия городов приходят на русском и матчатся с cities.name.
 * Любой сбой (таймаут, лимит, status=fail) деградирует в null, не в ошибку.
 */
final class IpApiGeolocator implements IpGeolocatorInterface
{
    public function locate(string $ip): ?IpLocationData
    {
        // Приватные/зарезервированные адреса (локальное окружение, прямой
        // доступ без прокси) провайдер не резолвит — не тратим запрос из лимита.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($isPublic === false) {
            return null;
        }

        $cacheKey = 'geoip:'.$ip;

        /** @var IpLocationData|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached instanceof IpLocationData) {
            return $cached;
        }

        $location = $this->fetch($ip);

        // Неудачные лукапы не кешируем: временный сбой провайдера не должен
        // залипать на сутки.
        if ($location !== null) {
            Cache::put($cacheKey, $location, (int) config('services.geoip.cache_ttl', 86400));
        }

        return $location;
    }

    private function fetch(string $ip): ?IpLocationData
    {
        try {
            $response = Http::baseUrl((string) config('services.geoip.base_url'))
                ->connectTimeout(3)
                ->timeout((int) config('services.geoip.timeout', 5))
                ->get('/json/'.$ip, [
                    'fields' => 'status,message,countryCode,city',
                    'lang' => 'ru',
                ]);

            if ($response->failed()) {
                Log::warning('GeoIP: провайдер вернул ошибку', [
                    'ip' => $ip,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            if ($response->json('status') !== 'success') {
                Log::info('GeoIP: локация не определена', [
                    'ip' => $ip,
                    'message' => $response->json('message'),
                ]);

                return null;
            }

            $countryCode = (string) $response->json('countryCode', '');

            if ($countryCode === '') {
                return null;
            }

            $city = $response->json('city');

            return new IpLocationData(
                countryCode: $countryCode,
                city: is_string($city) && $city !== '' ? $city : null,
            );
        } catch (\Throwable $e) {
            Log::warning('GeoIP: запрос к провайдеру не удался', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
