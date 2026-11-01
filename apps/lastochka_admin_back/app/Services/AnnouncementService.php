<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Status;

class AnnouncementService
{
    /**
     * Обработка изображения уведомления
     */
    public function fillImage(Announcement $announcement, array $args): void
    {
        if (!isset($args['image'])) {
            return;
        }

        $imageData = $args['image'];

        $existingJoin = $announcement->joinImage;

        if ($existingJoin) {
            if ($imageData === null) {
                $announcement->deleteImg($existingJoin);
            } else {
                $existingJoin->img->update($imageData);
            }
        } elseif ($imageData !== null) {
            $announcement->saveImg($imageData, Status::CODE_ANNOUNCEMENT_IMG, $announcement->getTable());
        }
    }
}