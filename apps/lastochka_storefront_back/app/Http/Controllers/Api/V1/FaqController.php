<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FaqResource;
use App\Models\Faq;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('FAQ')]
final class FaqController extends Controller
{
    /**
     * Вопросы и ответы
     *
     * Возвращает активные вопросы FAQ в порядке отображения
     * (position по возрастанию, вопросы без позиции — в конце).
     *
     * @response array{data: FaqResource[]}
     */
    public function index(): AnonymousResourceCollection
    {
        $faqs = Faq::query()
            ->active()
            ->orderByRaw('position is null, position')
            ->orderBy('id')
            ->get();

        return FaqResource::collection($faqs);
    }
}
