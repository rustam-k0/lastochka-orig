<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     phone: string,
     *     firstName: string|null,
     *     lastName: string|null,
     *     patronymic: string|null,
     *     email: string|null,
     *     birthDate: string|null,
     *     gender: string|null,
     *     bonusBalance: int,
     *     adultConfirmed: bool,
     *     readyTimeModalSuppressed: bool
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'phone' => (string) $this->phone,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'patronymic' => $this->patronymic,
            'email' => $this->email,
            'birthDate' => $this->birth_date ? $this->birth_date->toIso8601String() : null,
            'gender' => $this->gender ? $this->gender->value : null,
            'bonusBalance' => (int) ($this->bonus_balance ?? 0),
            'adultConfirmed' => (bool) ($this->adult_confirmed ?? false),
            'readyTimeModalSuppressed' => (bool) ($this->ready_time_modal_suppressed ?? false),
        ];
    }
}
