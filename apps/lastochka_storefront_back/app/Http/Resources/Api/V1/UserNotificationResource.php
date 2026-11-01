<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\UserNotification
 */
final class UserNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'shortDescription' => $this->short_description,
            'body' => $this->body,
            'imageUrl' => $this->image_url ? ImageResource::toFullUrl($this->image_url) : null,
            'promoCode' => $this->promo_code,
            'deepLink' => $this->deep_link,
            'announcementId' => $this->announcement_id,
            'isRead' => (bool) $this->is_read,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
