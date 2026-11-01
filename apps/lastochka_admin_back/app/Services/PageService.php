<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PageService
{
    /**
     * Позиция для новой страницы, если админ её не задал — в конец списка.
     */
    public function nextPosition(): int
    {
        return (int) Page::max('position') + 1;
    }

    /**
     * Перестановка страницы на заданное место в списке (нумерация с 1).
     *
     * Порядок пересчитывается целиком и становится плотным (1..N) —
     * это снимает проблему пустых и повторяющихся позиций.
     * Обновление идёт по одной модели, чтобы сработал outbox-observer
     * и новые позиции доехали в storefront (bulk update событий не шлёт).
     */
    public function changePosition(Page $page, int $newPosition): Page
    {
        DB::transaction(function () use ($page, $newPosition) {
            $ordered = Page::query()
                ->orderByRaw('position is null, position')
                ->orderBy('id')
                ->get();

            $ids = $ordered->pluck('id')->all();
            $currentIndex = array_search($page->id, $ids, true);

            if ($currentIndex === false) {
                return;
            }

            array_splice($ids, $currentIndex, 1);

            $targetIndex = max(1, min($newPosition, count($ids) + 1)) - 1;
            array_splice($ids, $targetIndex, 0, [$page->id]);

            foreach ($ids as $index => $id) {
                $model = $ordered->firstWhere('id', $id);
                $position = $index + 1;

                if ($model && $model->position !== $position) {
                    $model->update(['position' => $position]);
                }
            }
        });

        return $page->fresh();
    }

    /**
     * Код страницы из названия: транслитерация кириллицы,
     * при коллизии добавляется числовой суффикс.
     */
    public function generateSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title, '-', 'ru');

        if ($base === '') {
            $base = 'page';
        }

        // Оставляем запас под суффикс: колонка ограничена 100 символами.
        // rtrim, а не mb_-аналог: на этом этапе $base — вывод Str::slug(),
        // строго ASCII (латиница, цифры, дефис), многобайтовых символов
        // в ней уже нет, поэтому байтовая обрезка дефисов безопасна.
        // Без этого обрыв обрезки ровно на дефисе оставляет код вида
        // "...-" (а при коллизии — "...--2"), что не проходит regex
        // валидатора slug.
        $base = rtrim(mb_substr($base, 0, 80), '-');

        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Код занят, если его использует любая запись, включая мягко удалённые:
     * unique-индекс в БД удалённые записи тоже видит.
     */
    private function slugTaken(string $slug, ?int $ignoreId = null): bool
    {
        return Page::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
