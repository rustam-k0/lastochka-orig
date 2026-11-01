<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs;

use App\DTOs\ObjectAddressData;
use PHPUnit\Framework\TestCase;

final class ObjectAddressDataTest extends TestCase
{
    public function test_constructs_with_required_name_only(): void
    {
        $dto = new ObjectAddressData(name: 'Многоэтажка');

        $this->assertSame('Многоэтажка', $dto->name);
        $this->assertNull($dto->contactName);
        $this->assertFalse($dto->is247);
        $this->assertSame([], $dto->workingDays);
        $this->assertFalse($dto->isDaily);
    }

    public function test_constructs_with_full_data(): void
    {
        $dto = new ObjectAddressData(
            name: 'Многоэтажка',
            contactName: 'Иванов И. И.',
            contactPhone: '+79991234567',
            company: 'Звёздочка',
            liftSize: '1,5 на 2',
            hasLift: true,
            needsPass: false,
            canOversized: true,
            workingHoursFrom: '10:00',
            workingHoursTo: '20:00',
            is247: false,
            workingDays: ['mon', 'tue'],
            isDaily: false,
        );

        $this->assertSame('Иванов И. И.', $dto->contactName);
        $this->assertSame('+79991234567', $dto->contactPhone);
        $this->assertTrue($dto->hasLift);
        $this->assertSame(['mon', 'tue'], $dto->workingDays);
    }

    public function test_to_array_uses_snake_case_keys(): void
    {
        $dto = new ObjectAddressData(
            name: 'Объект',
            contactName: 'Иванов',
            workingHoursFrom: '09:00',
            workingHoursTo: '18:00',
            workingDays: ['mon'],
            is247: true,
            isDaily: true,
            hasLift: true,
        );

        $arr = $dto->toArray();

        $this->assertSame('Объект', $arr['name']);
        $this->assertSame('Иванов', $arr['contact_name']);
        $this->assertSame('09:00', $arr['working_hours_from']);
        $this->assertSame('18:00', $arr['working_hours_to']);
        $this->assertSame(['mon'], $arr['working_days']);
        $this->assertTrue($arr['is_24_7']);
        $this->assertTrue($arr['is_daily']);
        $this->assertTrue($arr['has_lift']);
        $this->assertArrayNotHasKey('photos', $arr);
    }

    public function test_from_array_accepts_snake_case_payload(): void
    {
        $dto = ObjectAddressData::fromArray([
            'name' => 'Объект',
            'contact_name' => 'Иванов',
            'contact_phone' => '+79991234567',
            'has_lift' => true,
            'needs_pass' => null,
            'working_hours_from' => '10:00',
            'working_hours_to' => '20:00',
            'is_24_7' => false,
            'working_days' => ['mon', 'tue'],
            'is_daily' => false,
        ]);

        $this->assertSame('Иванов', $dto->contactName);
        $this->assertTrue($dto->hasLift);
        $this->assertNull($dto->needsPass);
        $this->assertSame(['mon', 'tue'], $dto->workingDays);
    }
}
