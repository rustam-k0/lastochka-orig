<?php

namespace App\Services;

use App\Models\JoinImg;
use App\Models\Status;
use App\Models\Story;

class StoryService
{
    /**
     * Заполнение изображений истории
     */
    public function fillImages(Story $story, array $args): void
    {
        $this->fillThumbnailImage($story, $args);
        $this->fillSliderImages($story, $args);
    }

    /**
     * Заполнение thumbnail изображения
     */
    protected function fillThumbnailImage(Story $story, array $args): void
    {
        if (!isset($args['thumbnail_image'])) {
            return;
        }

        $existingJoin = $story->joinThumbnailImage;

        if ($existingJoin) {
            if ($args['thumbnail_image'] === null || $args['thumbnail_image'] === []) {
                $story->deleteImg($existingJoin);
            } else {
                $story->updateImg($existingJoin, $args['thumbnail_image']);
            }
        } else {
            if ($args['thumbnail_image'] !== null && $args['thumbnail_image'] !== []) {
                $story->saveImg($args['thumbnail_image'], Status::CODE_STORY_THUMBNAIL);
            }
        }
    }

    /**
     * Заполнение slider изображений
     */
    protected function fillSliderImages(Story $story, array $args): void
    {
        if (!isset($args['images'])) {
            return;
        }

        $existingJoinImages = $story->joinImages;
        $newImages = $args['images'] ?? [];

        if (empty($newImages)) {
            foreach ($existingJoinImages as $joinImg) {
                $story->deleteImg($joinImg);
            }
            return;
        }

        $processedImgIds = [];

        foreach ($newImages as $position => $imgData) {
            if (isset($imgData['id'])) {
                $existingJoin = $existingJoinImages->where('img_id', $imgData['id'])->first();

                if ($existingJoin && $existingJoin->img) {
                    $existingJoin->img->update($imgData);
                    $processedImgIds[] = $existingJoin->img_id;
                }
            } else {
                if ($imgData !== null && $imgData !== []) {
                    $savedJoin = $story->saveImg($imgData, Status::CODE_STORY_SLIDER);
                    $processedImgIds[] = $savedJoin->img_id;
                }
            }
        }

        foreach ($existingJoinImages as $joinImg) {
            if (!in_array($joinImg->img_id, $processedImgIds)) {
                $story->deleteImg($joinImg);
            }
        }
    }

    /**
     * Изменение позиции истории с пересчётом позиций других историй
     */
    public function changePosition(Story $story, int $newPosition): Story
    {
        $oldPosition = $story->position;

        if ($oldPosition === $newPosition) {
            return $story;
        }

        \DB::transaction(function () use ($story, $oldPosition, $newPosition) {
            if ($newPosition > $oldPosition) {
                Story::where('position', '>', $oldPosition)
                    ->where('position', '<=', $newPosition)
                    ->where('id', '!=', $story->id)
                    ->decrement('position');
            } else {
                Story::where('position', '>=', $newPosition)
                    ->where('position', '<', $oldPosition)
                    ->where('id', '!=', $story->id)
                    ->increment('position');
            }

            $story->update(['position' => $newPosition]);
        });

        return $story->fresh();
    }
}
