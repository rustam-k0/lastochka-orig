<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ObjectAddressData
{
    /**
     * @param  string[]  $workingDays  Codes: mon|tue|wed|thu|fri|sat|sun
     */
    public function __construct(
        public string $name,
        public ?string $contactName = null,
        public ?string $contactPhone = null,
        public ?string $company = null,
        public ?string $liftSize = null,
        public ?bool $hasLift = null,
        public ?bool $needsPass = null,
        public ?bool $canOversized = null,
        public ?string $workingHoursFrom = null,
        public ?string $workingHoursTo = null,
        public bool $is247 = false,
        public array $workingDays = [],
        public bool $isDaily = false,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            name: (string) ($raw['name'] ?? ''),
            contactName: self::nullableString($raw, 'contact_name'),
            contactPhone: self::nullableString($raw, 'contact_phone'),
            company: self::nullableString($raw, 'company'),
            liftSize: self::nullableString($raw, 'lift_size'),
            hasLift: self::nullableBool($raw, 'has_lift'),
            needsPass: self::nullableBool($raw, 'needs_pass'),
            canOversized: self::nullableBool($raw, 'can_oversized'),
            workingHoursFrom: self::nullableString($raw, 'working_hours_from'),
            workingHoursTo: self::nullableString($raw, 'working_hours_to'),
            is247: (bool) ($raw['is_24_7'] ?? false),
            workingDays: array_values(array_filter(
                (array) ($raw['working_days'] ?? []),
                fn ($v) => is_string($v) && $v !== ''
            )),
            isDaily: (bool) ($raw['is_daily'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'company' => $this->company,
            'lift_size' => $this->liftSize,
            'has_lift' => $this->hasLift,
            'needs_pass' => $this->needsPass,
            'can_oversized' => $this->canOversized,
            'working_hours_from' => $this->workingHoursFrom,
            'working_hours_to' => $this->workingHoursTo,
            'is_24_7' => $this->is247,
            'working_days' => $this->workingDays,
            'is_daily' => $this->isDaily,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function nullableString(array $raw, string $key): ?string
    {
        $v = $raw[$key] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function nullableBool(array $raw, string $key): ?bool
    {
        return array_key_exists($key, $raw) && $raw[$key] !== null
            ? (bool) $raw[$key]
            : null;
    }
}
