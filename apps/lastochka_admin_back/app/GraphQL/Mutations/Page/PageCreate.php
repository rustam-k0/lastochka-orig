<?php

namespace App\GraphQL\Mutations\Page;

use App\Models\Page;
use App\Services\PageService;
use Illuminate\Support\Facades\DB;

final class PageCreate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Page
    {
        return DB::transaction(function () use ($args) {
            $service = new PageService();
            $isPublished = (bool) ($args['is_published'] ?? false);
            $slug = $args['slug'] ?? null;

            if ($slug === null || trim($slug) === '') {
                $slug = $service->generateSlug($args['title']);
            }

            return Page::create([
                'slug' => $slug,
                'title' => $args['title'],
                'content' => $args['content'],
                'meta_title' => $args['meta_title'] ?? null,
                'meta_description' => $args['meta_description'] ?? null,
                'position' => $args['position'] ?? $service->nextPosition(),
                'is_published' => $isPublished,
                // Дата публикации ведётся системой: проставляется в момент,
                // когда страница становится опубликованной.
                'published_at' => $isPublished ? now() : null,
                // Системные страницы создаются только миграцией.
                'is_system' => false,
            ]);
        });
    }
}
