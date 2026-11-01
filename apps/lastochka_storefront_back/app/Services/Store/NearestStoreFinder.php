<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Поиск ближайших магазинов по координатам пользователя.
 *
 * Расстояние считается формулой гаверсинуса в PHP, а не в SQL: выборка
 * предварительно сужается bounding box'ом по широте/долготе (обычные сравнения,
 * одинаково работают в MySQL и SQLite), поэтому до расчёта доезжает небольшой
 * набор магазинов.
 */
final class NearestStoreFinder
{
    /** Радиус Земли в километрах. */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Ближайшие магазины, отсортированные по возрастанию расстояния.
     *
     * @param  float|null  $radiusKm  Ограничение по расстоянию, null — без ограничения
     * @return Collection<int, array{store: Store, distance: float}>
     */
    public function find(
        float $latitude,
        float $longitude,
        ?User $user = null,
        ?int $cityId = null,
        bool $pickupOnly = false,
        ?float $radiusKm = null,
        int $limit = 1,
    ): Collection {
        $query = Store::query()
            ->active()
            ->forUserType($user)
            ->inCity($cityId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['schedules', 'city']);

        if ($pickupOnly) {
            $query->pickupEnabled();
        }

        if ($radiusKm !== null) {
            $this->applyBoundingBox($query, $latitude, $longitude, $radiusKm);
        }

        return $query->get()
            ->map(fn (Store $store) => [
                'store' => $store,
                'distance' => $this->distanceKm(
                    $latitude,
                    $longitude,
                    (float) $store->latitude,
                    (float) $store->longitude,
                ),
            ])
            ->when(
                $radiusKm !== null,
                fn (Collection $items) => $items->filter(
                    fn (array $item) => $item['distance'] <= $radiusKm
                )
            )
            ->sortBy('distance')
            ->take($limit)
            ->values();
    }

    /**
     * Ближайший магазин или null, если подходящих магазинов нет.
     *
     * @return array{store: Store, distance: float}|null
     */
    public function findNearest(
        float $latitude,
        float $longitude,
        ?User $user = null,
        ?int $cityId = null,
        bool $pickupOnly = false,
        ?float $radiusKm = null,
    ): ?array {
        return $this->find($latitude, $longitude, $user, $cityId, $pickupOnly, $radiusKm)->first();
    }

    /**
     * Расстояние между двумя точками по формуле гаверсинуса, км.
     */
    public function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = $latTo - $latFrom;
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Грубый предфильтр по прямоугольнику вокруг точки — отсекает заведомо
     * далёкие магазины до расчёта расстояния.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Store>  $query
     */
    private function applyBoundingBox($query, float $latitude, float $longitude, float $radiusKm): void
    {
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);

        // На полюсах cos(lat) → 0, дельта долготы устремляется в бесконечность:
        // в этом случае по долготе не фильтруем вовсе.
        $cosLat = cos(deg2rad($latitude));
        $lonDelta = abs($cosLat) < 1e-9
            ? 180.0
            : rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cosLat));

        $query->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta]);

        if ($lonDelta < 180.0) {
            $query->whereBetween('longitude', [$longitude - $lonDelta, $longitude + $lonDelta]);
        }
    }
}
