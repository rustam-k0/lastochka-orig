<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrderAddress
 */
final class OrderAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = [
            'id' => (int) $this->id,
            'country' => $this->country,
            'city' => $this->city,
            'street' => $this->street,
            'houseNumber' => $this->house_number,
            'entrance' => $this->entrance,
            'floor' => $this->floor,
            'apartment' => $this->apartment,
            'intercom' => $this->intercom,
            'comment' => $this->comment,
            'fullAddress' => $this->full_address,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
        ];

        $moduleEnabled = app(ModuleConfigService::class)->isAddressObjectsEnabled();
        $objectData = is_array($this->object_data) ? $this->object_data : null;

        if ($moduleEnabled && $objectData !== null && ! empty($objectData['name'])) {
            $base['objectData'] = self::camelizeObjectData($objectData);
        } else {
            $base['objectData'] = null;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $snake
     * @return array<string, mixed>
     */
    private static function camelizeObjectData(array $snake): array
    {
        return [
            'name' => $snake['name'] ?? null,
            'contactName' => $snake['contact_name'] ?? null,
            'contactPhone' => $snake['contact_phone'] ?? null,
            'company' => $snake['company'] ?? null,
            'liftSize' => $snake['lift_size'] ?? null,
            'hasLift' => $snake['has_lift'] ?? null,
            'needsPass' => $snake['needs_pass'] ?? null,
            'canOversized' => $snake['can_oversized'] ?? null,
            'workingHoursFrom' => $snake['working_hours_from'] ?? null,
            'workingHoursTo' => $snake['working_hours_to'] ?? null,
            'is247' => (bool) ($snake['is_24_7'] ?? false),
            'workingDays' => $snake['working_days'] ?? [],
            'isDaily' => (bool) ($snake['is_daily'] ?? false),
            'photos' => array_map(
                fn ($p) => [
                    'id' => isset($p['id']) ? (int) $p['id'] : null,
                    'url' => $p['url'] ?? '',
                    'mime' => $p['mime'] ?? null,
                ],
                $snake['photos'] ?? [],
            ),
        ];
    }
}
