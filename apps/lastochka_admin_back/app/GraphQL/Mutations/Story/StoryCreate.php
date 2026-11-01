<?php

namespace App\GraphQL\Mutations\Story;

use App\Models\Story;
use App\Services\StoryService;
use Illuminate\Support\Facades\DB;

final class StoryCreate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Story
    {
        return DB::transaction(function () use ($args) {
            $args = \App\Services\CustomCheckboxService::sanitizeArgs('Story', $args);
            $story = Story::create([
                'title' => $args['title'],
                'expires_at' => $args['expires_at'],
                'link_type' => $args['link_type'] ?? Story::LINK_TYPE_NONE,
                'product_id' => $args['product_id'] ?? null,
                'shop_id' => $args['shop_id'] ?? null,
                'external_url' => $args['external_url'] ?? null,
                'position' => $args['position'] ?? 0,
                'is_active' => $args['is_active'] ?? true,
                'is_quick_category' => $args['is_quick_category'] ?? false,
                'custom_checkboxes' => $args['custom_checkboxes'] ?? null,
            ]);

            $story->clearIrrelevantLinkFields();

            $service = new StoryService();
            $service->fillImages($story, $args);

            return $story->fresh(['thumbnailImage', 'images', 'product', 'shop']);
        });
    }
}
