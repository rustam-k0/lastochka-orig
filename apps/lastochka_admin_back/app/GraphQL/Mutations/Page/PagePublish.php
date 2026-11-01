<?php

namespace App\GraphQL\Mutations\Page;

use App\Models\Page;
use App\Rules\EditorJsDocument;
use GraphQL\Error\Error;

final class PagePublish
{
    /**
     * Публикация страницы: становится видна в мобильном приложении и на сайте.
     *
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Page
    {
        $page = Page::findOrFail($args['id']);

        $rule = new EditorJsDocument();

        if (!$rule->passes('content', $page->content)) {
            throw new Error($rule->message());
        }

        $page->update([
            'is_published' => true,
            // Дата первой публикации не перезаписывается: она фиксирует момент,
            // когда страница впервые стала доступна пользователям.
            'published_at' => $page->published_at ?? now(),
        ]);

        return $page->fresh();
    }
}
