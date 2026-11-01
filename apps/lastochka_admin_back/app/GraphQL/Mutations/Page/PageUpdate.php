<?php

namespace App\GraphQL\Mutations\Page;

use App\Models\Page;
use Illuminate\Support\Facades\DB;

final class PageUpdate
{
    /**
     * Поля, которые обновляются только если пришли в запросе:
     * частичное обновление не должно затирать остальные данные страницы.
     */
    private const UPDATABLE_FIELDS = [
        'slug',
        'title',
        'content',
        'meta_title',
        'meta_description',
        'position',
    ];

    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Page
    {
        return DB::transaction(function () use ($args) {
            $page = Page::findOrFail($args['id']);

            $attributes = [];

            foreach (self::UPDATABLE_FIELDS as $field) {
                if (array_key_exists($field, $args)) {
                    $attributes[$field] = $args[$field];
                }
            }

            if (array_key_exists('is_published', $args)) {
                $isPublished = (bool) $args['is_published'];
                $attributes['is_published'] = $isPublished;

                // Дата публикации фиксирует момент, когда страница впервые
                // стала доступна пользователям, и больше не перезаписывается.
                if ($isPublished && $page->published_at === null) {
                    $attributes['published_at'] = now();
                }
            }

            $page->update($attributes);

            return $page->fresh();
        });
    }
}
