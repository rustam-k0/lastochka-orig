<?php

namespace App\GraphQL\Mutations\Announcement;

use App\Models\Announcement;
use App\Services\NotificationService;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SendAnnouncementNotification
{
    public function __invoke($_, array $args, GraphQLContext $context)
    {
        $announcement = Announcement::findOrFail($args['announcement_id']);

        $service = app(NotificationService::class);
        $result = $service->sendAnnouncementNotification(
            $announcement,
            $args['target'] ?? 'ALL_USERS',
            $args['segment_criteria'] ?? []
        );

        return [
            'success' => true,
            'message' => 'Уведомление поставлено в очередь на отправку',
            'target' => $args['target'] ?? 'ALL_USERS',
            'announcement' => $announcement,
        ];
    }
}