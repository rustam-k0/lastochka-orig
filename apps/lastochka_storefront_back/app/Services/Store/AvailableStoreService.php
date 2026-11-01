<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\Address;
use App\Models\Store;
use App\Models\User;
use App\Services\Delivery\ZoneDeliveryLadder;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class AvailableStoreService
{
    private const CACHE_PREFIX = 'available_stores:address:';

    public function __construct(
        private readonly SlotsApiClientInterface $slotsApiClient
    ) {}

    /**
     * Получить доступные магазины для адреса.
     *
     * @return Collection<int, Store>
     */
    public function getAvailableStores(Address $address, ?User $user = null): Collection
    {
        $cachedData = $this->getCachedData($address->id);

        if ($cachedData === null) {
            $cachedData = $this->fetchAndCacheStores($address);
        }

        if (empty($cachedData['store_codes'])) {
            return new Collection;
        }

        return Store::query()
            ->active()
            ->forUserType($user)
            ->whereIn('code', $cachedData['store_codes'])
            ->with(['schedules', 'city'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить информацию о доставке для магазина.
     *
     * deliveryPriceRanges (MID-280): ступени зоны; у зоны без ступеней, как и у записи
     * кэша до выкладки, бесплатная ступень из freeDeliveryFrom (прежний порог).
     *
     * @return array{deliveryPrice: int, minOrderAmount: int, freeDeliveryFrom: int|null, deliveryPriceRanges: array<int, array{from_amount: int, price: int}>}|null
     */
    public function getDeliveryInfo(Address $address, Store $store): ?array
    {
        $cachedData = $this->getCachedData($address->id);

        if ($cachedData === null) {
            $cachedData = $this->fetchAndCacheStores($address);
        }

        $storeCode = $store->code;

        if (! isset($cachedData['store_data'][$storeCode])) {
            return null;
        }

        $data = $cachedData['store_data'][$storeCode];
        $freeDeliveryFrom = isset($data['free_delivery_from']) ? (int) $data['free_delivery_from'] : null;

        return [
            'deliveryPrice' => (int) $data['delivery_price'],
            'minOrderAmount' => (int) $data['min_order_price'],
            'freeDeliveryFrom' => $freeDeliveryFrom,
            'deliveryPriceRanges' => ZoneDeliveryLadder::make(
                (int) $data['delivery_price'],
                $data['delivery_price_ranges'] ?? null,
                $freeDeliveryFrom,
            )->ranges(),
        ];
    }

    /**
     * Обновить кеш для адреса.
     */
    public function refreshCache(Address $address): void
    {
        $this->invalidateCache($address->id);
        $this->fetchAndCacheStores($address);
    }

    /**
     * Инвалидировать кеш для адреса.
     */
    public function invalidateCache(int $addressId): void
    {
        Cache::forget($this->getCacheKey($addressId));
    }

    /**
     * Инвалидировать кеш доступных магазинов для всех адресов.
     *
     * Why: ответ slot-api закеширован per-address на час, а при изменении
     * shop_zones.min_order_price / delivery_price / free_delivery_from
     * или флагов магазина значения должны обновиться сразу.
     */
    public function invalidateAllAddressCaches(): void
    {
        Address::query()
            ->select('id')
            ->chunkById(1000, function ($addresses): void {
                foreach ($addresses as $address) {
                    $this->invalidateCache((int) $address->id);
                }
            });
    }

    /**
     * Получить данные из кеша.
     *
     * @return array{store_codes: string[], store_data: array<string, array{delivery_price: int, min_order_price: int, free_delivery_from: int|null, delivery_price_ranges?: array<int, array{from_amount: int, price: int}>}>, cached_at: string}|null
     */
    private function getCachedData(int $addressId): ?array
    {
        return Cache::get($this->getCacheKey($addressId));
    }

    /**
     * Получить и закешировать магазины.
     *
     * @return array{store_codes: string[], store_data: array<string, array{delivery_price: int, min_order_price: int, free_delivery_from: int|null, delivery_price_ranges?: array<int, array{from_amount: int, price: int}>}>, cached_at: string}
     */
    private function fetchAndCacheStores(Address $address): array
    {
        $shops = $this->slotsApiClient->getAvailableShops(
            (float) $address->latitude,
            (float) $address->longitude
        );

        $storeCodes = [];
        $storeData = [];

        foreach ($shops as $shop) {
            $code = $shop['code'];
            $storeCodes[] = $code;
            $storeData[$code] = [
                'delivery_price' => $shop['delivery_price'],
                'min_order_price' => $shop['min_order_price'],
                'free_delivery_from' => $shop['free_delivery_from'] ?? null,
                'delivery_price_ranges' => $shop['delivery_price_ranges'] ?? [],
            ];
        }

        $existingCodes = Store::query()
            ->whereIn('code', $storeCodes)
            ->pluck('code')
            ->all();

        $missingCodes = array_diff($storeCodes, $existingCodes);
        if (! empty($missingCodes)) {
            Log::warning('Stores not found in database', [
                'address_id' => $address->id,
                'missing_codes' => $missingCodes,
            ]);
        }

        $storeCodes = array_values(array_intersect($storeCodes, $existingCodes));

        $filteredStoreData = [];
        foreach ($storeCodes as $code) {
            $filteredStoreData[$code] = $storeData[$code];
        }

        $data = [
            'store_codes' => $storeCodes,
            'store_data' => $filteredStoreData,
            'cached_at' => now()->toIso8601String(),
        ];

        $ttl = (int) config('services.slots_api.cache_ttl', 3600);
        Cache::put($this->getCacheKey($address->id), $data, $ttl);

        return $data;
    }

    private function getCacheKey(int $addressId): string
    {
        return self::CACHE_PREFIX.$addressId;
    }
}
