<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StoryResource;
use App\Models\Story;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Stories', weight: 8)]
final class StoryController extends Controller
{
    /**
     * Список историй
     *
     * Возвращает список активных историй с изображениями.
     * Истории с истекшим сроком автоматически исключаются.
     * Сортировка по дате создания, от новых к старым. Пагинации нет.
     *
     * @response array{data: StoryResource[]}
     */
    #[QueryParameter('isQuickCategory', description: 'Фильтр: true возвращает только быстрые категории, false только обычные сториз. Без параметра возвращаются все.', type: 'boolean', required: false)]
    #[QueryParameter('is_quick_category', description: 'Deprecated snake_case алиас isQuickCategory. При передаче обоих параметров приоритет у isQuickCategory.', type: 'boolean', required: false, deprecated: true)]
    public function index(Request $request): AnonymousResourceCollection
    {
        // Backward-compat: принимаем deprecated snake_case `is_quick_category` как алиас `isQuickCategory`.
        if ($request->has('is_quick_category') && ! $request->has('isQuickCategory')) {
            $request->merge(['isQuickCategory' => $request->input('is_quick_category')]);
        }

        $query = Story::query()
            ->available()
            ->with('images');

        if ($request->has('isQuickCategory')) {
            $query->where('is_quick_category', filter_var($request->input('isQuickCategory'), FILTER_VALIDATE_BOOLEAN));
        }

        $stories = $query->orderByDesc('created_at')->get();

        return StoryResource::collection($stories);
    }

    /**
     * Детали истории
     *
     * Возвращает детальную информацию об истории, включая все изображения.
     * Неактивная или истёкшая история отдаёт 404.
     *
     * @response array{data: StoryResource}
     */
    #[ScrambleResponse(404, description: 'История не найдена, неактивна или истекла', type: 'array{message: string}')]
    public function show(Story $story): StoryResource
    {
        abort_unless($story->is_active && $story->expires_at->isFuture(), 404);

        $story->load('images');

        return new StoryResource($story);
    }
}
