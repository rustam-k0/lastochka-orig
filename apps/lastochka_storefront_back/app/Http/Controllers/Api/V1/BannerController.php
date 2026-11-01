<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Banner\GetStoreBannersRequest;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Store;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Banners', weight: 9)]
final class BannerController extends Controller
{
    /**
     * Баннеры магазина
     *
     * Возвращает список активных баннеров указанного магазина.
     * Баннеры отсортированы по позиции и отфильтрованы по датам показа.
     *
     * Без параметра `categoryId` возвращаются только баннеры главной страницы.
     * С параметром `categoryId` возвращаются только баннеры страницы указанной
     * категории (пустой массив, если для категории баннер не задан).
     *
     * Параметр `placement` дополнительно оставляет только баннеры, у которых
     * включён соответствующий тумблер места показа (кастомный чекбокс баннера
     * из настроек админки, ключи динамические). Комбинируется с `categoryId`
     * через AND. Без `placement` тумблеры не учитываются — это слайдер главной.
     * Неизвестный или выключенный в настройках ключ — 422 со списком допустимых.
     * Пагинации нет.
     *
     * @response array{data: BannerResource[]}
     */
    #[QueryParameter('categoryId', description: 'ID категории: вернуть баннеры страницы этой категории вместо баннеров главной', type: 'int', required: false, example: 15)]
    #[ScrambleResponse(404, description: 'Магазин не найден', type: 'array{message: string}')]
    #[ScrambleResponse(422, description: 'Неизвестное место показа (placement)', type: 'array{message: string, errors: array{placement: string[]}}')]
    public function index(GetStoreBannersRequest $request, Store $store): AnonymousResourceCollection
    {
        $query = $store->banners()
            ->active()
            ->withinDates();

        if ($request->filled('categoryId')) {
            $query->forDisplayCategory($request->integer('categoryId'));
        } else {
            $query->forMainPage();
        }

        if ($request->placement() !== null) {
            $query->forPlacement($request->placement());
        }

        $banners = $query->orderBy('position')->get();

        return BannerResource::collection($banners);
    }
}
