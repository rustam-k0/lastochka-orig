<?php

namespace App\GraphQL\Mutations\Story;

use App\Models\Story;
use App\Services\StoryService;
use Illuminate\Support\Facades\DB;

final class StoryUpdate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Story
    {
        return DB::transaction(function () use ($args) {
            $story = Story::findOrFail($args['id']);

            $args = \App\Services\CustomCheckboxService::sanitizeArgs('Story', $args);
            $updateData = array_filter([
                'title' => $args['title'] ?? null,
                'expires_at' => $args['expires_at'] ?? null,
                'link_type' => $args['link_type'] ?? null,
                'product_id' => $args['product_id'] ?? null,
                'shop_id' => $args['shop_id'] ?? null,
                'external_url' => $args['external_url'] ?? null,
                'position' => $args['position'] ?? null,
                'is_active' => $args['is_active'] ?? null,
            ], function ($value) {
                return $value !== null;
            });

            if (isset($args['is_active'])) {
                $updateData['is_active'] = $args['is_active'];
            }

            if (isset($args['is_quick_category'])) {
                $updateData['is_quick_category'] = $args['is_quick_category'];
            }

            if (array_key_exists('custom_checkboxes', $args)) {
                $updateData['custom_checkboxes'] = $args['custom_checkboxes'];
            }

            if (!empty($updateData)) {
                $story->update($updateData);
            }

            if (isset($args['link_type'])) {
                $story->clearIrrelevantLinkFields();
            }

            $service = new StoryService();
            $service->fillImages($story, $args);

            // Картинки живут в img/join_img и не меняют модель Story: без touch()
            // правка только изображений не создаёт outbox-событие story.updated
            // и не синкается в storefront (LASTOCHKI-JAMI-12236). wasChanged()
            // защищает от дубля, когда update() выше уже опубликовал событие.
            if ((isset($args['thumbnail_image']) || isset($args['images'])) && !$story->wasChanged()) {
                $story->touch();
            }

            return $story->fresh(['thumbnailImage', 'images', 'product', 'shop']);
        });
    }
}
