<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductGroupFilterResource;
use App\Models\ProductGroupFilter;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('ProductGroupFilter', weight: 4)]
final class ProductGroupFilterController extends Controller
{
    public function __construct(
        private readonly ModuleConfigService $moduleConfig,
    ) {}

    /**
     * Список фильтров для товарных групп
     *
     * Возвращает активные фильтры комплексных решений с активными значениями,
     * отсортированные по позиции. При выключенном модуле `product_group_filters`
     * возвращается пустой список.
     *
     * @response array{data: ProductGroupFilterResource[]}
     */
    public function index(): AnonymousResourceCollection
    {
        if (! $this->moduleConfig->isProductGroupFiltersEnabled()) {
            return ProductGroupFilterResource::collection(collect());
        }

        $filters = ProductGroupFilter::query()
            ->active()
            ->with(['values' => fn ($q) => $q->active()->orderBy('position')])
            ->orderBy('position')
            ->get();

        return ProductGroupFilterResource::collection($filters);
    }
}
