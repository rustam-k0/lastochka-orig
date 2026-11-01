<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
final class NotificationSettingsResource extends JsonResource
{
    /**
     * @return array{ordersEnabled: bool, promoEnabled: bool, pushEnabled: bool, emailEnabled: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'ordersEnabled' => (bool) ($this->orders_enabled ?? true),
            'promoEnabled' => (bool) ($this->promo_enabled ?? true),
            'pushEnabled' => (bool) ($this->push_enabled ?? true),
            'emailEnabled' => (bool) ($this->email_enabled ?? false),
        ];
    }
}
