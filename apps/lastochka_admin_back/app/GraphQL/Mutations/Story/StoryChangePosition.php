<?php

namespace App\GraphQL\Mutations\Story;

use App\Models\Story;
use App\Services\StoryService;

final class StoryChangePosition
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Story
    {
        $story = Story::findOrFail($args['id']);

        $service = new StoryService();
        return $service->changePosition($story, $args['position']);
    }
}
