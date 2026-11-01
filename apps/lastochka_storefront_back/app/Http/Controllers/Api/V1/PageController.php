<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PageListResource;
use App\Http\Resources\Api\V1\PageResource;
use App\Models\Page;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Pages')]
final class PageController extends Controller
{
    /**
     * Список страниц
     *
     * Возвращает опубликованные статичные страницы в порядке отображения
     * (position по возрастанию, страницы без позиции — в конце).
     * Содержимое страниц в списке не передаётся.
     *
     * @response array{data: PageListResource[]}
     */
    public function index(): AnonymousResourceCollection
    {
        $pages = Page::query()
            ->published()
            ->ordered()
            ->get();

        return PageListResource::collection($pages);
    }

    /**
     * Карточка страницы
     *
     * Возвращает страницу по её коду. Содержимое отдаётся EditorJS-документом.
     * Неопубликованная или удалённая страница отдаёт 404.
     */
    #[ScrambleResponse(404, description: 'Страница не найдена или снята с публикации', type: 'array{message: string}')]
    public function show(string $slug): PageResource
    {
        // На slug намеренно нет уникального индекса (см. контракт), поэтому
        // при дубле отдаём самую свежую запись, а не самую старую.
        $page = Page::query()
            ->where('slug', $slug)
            ->published()
            ->orderByDesc('id')
            ->firstOrFail();

        return new PageResource($page);
    }
}
