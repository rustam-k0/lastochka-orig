<?php

namespace App\GraphQL\Mutations\Page;

use App\Models\Page;
use GraphQL\Error\Error;

final class PageDelete
{
    /**
     * Удаление страницы (soft delete). Системную страницу удалить нельзя:
     * на её код завязаны ссылки в мобильном приложении.
     *
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args)
    {
        $page = Page::findOrFail($args['id']);

        if ($page->is_system) {
            throw new Error('Системную страницу удалить нельзя. Снимите её с публикации, если она не нужна.');
        }

        // Освобождаем код: unique-индекс видит и мягко удалённые записи,
        // иначе удалённая страница заняла бы свой код навсегда.
        // Обрезаем базу через mb_substr — код может содержать только латиницу,
        // но правило проекта одно для всех строк.
        $page->update([
            'slug' => mb_substr($page->slug, 0, 80) . '--deleted-' . $page->id,
        ]);

        $page->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
