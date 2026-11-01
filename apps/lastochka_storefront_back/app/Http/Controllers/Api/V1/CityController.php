<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CityResource;
use App\Models\City;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('City', weight: 1)]
final class CityController extends Controller
{
    /**
     * Список активных городов.
     *
     * Публичный endpoint, авторизация не требуется, пагинации нет.
     * Города с is_default=true выводятся первыми, далее сортировка по названию.
     * `defaultStoreId` — магазин по умолчанию города (null, если не назначен).
     */
    public function index(): AnonymousResourceCollection
    {
        $cities = City::query()
            ->active()
            ->with('defaultStore')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return CityResource::collection($cities);
    }
}
