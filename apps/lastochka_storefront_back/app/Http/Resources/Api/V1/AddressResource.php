<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\ObjectAddressData;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\Address
 */
final class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = [
            'id' => (int) $this->id,
            'userId' => (int) $this->user_id,
            'title' => $this->title,
            'country' => $this->country,
            'city' => $this->city,
            'street' => $this->street,
            'houseNumber' => $this->house_number,
            'entrance' => $this->entrance,
            'floor' => $this->floor,
            'apartment' => $this->apartment,
            'intercom' => $this->intercom,
            'comment' => $this->comment,
            'latitude' => $this->latitude ? (float) $this->latitude : null,
            'longitude' => $this->longitude ? (float) $this->longitude : null,
            'fullAddress' => $this->full_address,
            'isActive' => (bool) $this->is_active,
            'isObject' => (bool) $this->is_object,
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];

        $moduleEnabled = app(ModuleConfigService::class)->isAddressObjectsEnabled();
        if ($this->is_object && $moduleEnabled && $this->object_data instanceof ObjectAddressData) {
            $base['objectData'] = array_merge(
                self::camelizeObjectData($this->object_data->toArray()),
                ['photos' => $this->photos->map(fn ($p) => [
                    'id' => (int) $p->id,
                    'url' => Storage::disk($p->disk)->url($p->path),
                    'mime' => $p->mime,
                ])->values()->all()],
            );
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
        ];
    }
}
