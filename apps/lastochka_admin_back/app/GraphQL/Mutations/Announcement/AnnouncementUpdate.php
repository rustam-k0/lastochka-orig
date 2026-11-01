<?php

namespace App\GraphQL\Mutations\Announcement;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Support\Facades\DB;

final class AnnouncementUpdate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Announcement
    {
        return DB::transaction(function () use ($args) {
            $announcement = Announcement::findOrFail($args['id']);

            $updateData = array_filter([
                'title' => $args['title'] ?? null,
                'short_description' => $args['short_description'] ?? null,
                'description' => $args['description'] ?? null,
                'promocode_id' => array_key_exists('promocode_id', $args) ? $args['promocode_id'] : null,
                'is_active' => array_key_exists('is_active', $args) ? $args['is_active'] : null,
            ], function ($value) {
                return $value !== null;
            });

            $announcement->update($updateData);

            $service = new AnnouncementService();
            $service->fillImage($announcement, $args);

            return $announcement->fresh(['image', 'promocode']);
        });
    }
}