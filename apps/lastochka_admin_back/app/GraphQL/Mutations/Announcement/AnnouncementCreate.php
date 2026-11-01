<?php

namespace App\GraphQL\Mutations\Announcement;

use App\Models\Announcement;
use App\Services\AnnouncementService;
use Illuminate\Support\Facades\DB;

final class AnnouncementCreate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Announcement
    {
        return DB::transaction(function () use ($args) {
            $announcement = Announcement::create([
                'title' => $args['title'],
                'short_description' => $args['short_description'],
                'description' => $args['description'],
                'promocode_id' => $args['promocode_id'] ?? null,
                'is_active' => $args['is_active'] ?? true,
            ]);

            $service = new AnnouncementService();
            $service->fillImage($announcement, $args);

            return $announcement->fresh(['image', 'promocode']);
        });
    }
}