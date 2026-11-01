<?php

namespace App\GraphQL\Mutations\Page;

use App\Models\Page;

final class PageUnpublish
{
    /**
     * Снятие страницы с публикации: перестаёт отдаваться витриной.
     * Дата публикации сохраняется как исторический факт.
     *
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Page
    {
        $page = Page::findOrFail($args['id']);

        $page->update(['is_published' => false]);

        return $page->fresh();
    }
}
