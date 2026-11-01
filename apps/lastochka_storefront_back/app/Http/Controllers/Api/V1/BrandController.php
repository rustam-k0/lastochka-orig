<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BrandResource;
use App\Models\Brand;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Brands', weight: 12)]
final class BrandController extends Controller
{
    /**
     * Список брендов
     *
     * Возвращает список активных брендов с логотипами, отсортированный
     * по `position`. Поле `rating` каждого бренда — средний рейтинг его
     * активных товаров (может быть `null`, если рейтингов нет).
     * Если модуль «Бренды» отключён в настройках — возвращает пустой список
     * со статусом 200.
     *
     * @response array{data: BrandResource[]}
     */
    public function index(ModuleConfigService $moduleConfig): AnonymousResourceCollection
    {
        if (! $moduleConfig->isBrandsEnabled()) {
            return BrandResource::collection(collect());
        }

        $brands = Brand::query()
            ->available()
            ->withAvg(['products as products_rating_avg' => fn ($q) => $q->where('is_active', true)], 'rating')
            ->orderBy('position')
            ->get();

        return BrandResource::collection($brands);
    }
}
