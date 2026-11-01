<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Настройки бонусной системы магазина.
 *
 * Ключи зеркалят блок `bonus` в CartResource, чтобы клиент использовал
 * одну и ту же модель данных на карточке магазина и в корзине.
 *
 * @mixin \App\Models\Store
 */
final class StoreBonusSettingsResource extends JsonResource
{
    /**
     * @return array{isEnabled: bool, earnPercent: int, spendPercent: int}
     */
    public function toArray(Request $request): array
    {
        $isEnabled = (bool) $this->is_bonus_enabled;

        return [
            'isEnabled' => $isEnabled,
            'earnPercent' => $isEnabled ? (int) ($this->bonus_earn_percent ?? 0) : 0,
            'spendPercent' => $isEnabled ? (int) ($this->bonus_spend_percent ?? 0) : 0,
        ];
    }
}
