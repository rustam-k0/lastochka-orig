<?php

namespace App\GraphQL\Mutations\Page;

use App\Models\Page;
use App\Services\PageService;

final class PageChangePosition
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Page
    {
        $page = Page::findOrFail($args['id']);

        return (new PageService())->changePosition($page, (int) $args['position']);
    }
}
