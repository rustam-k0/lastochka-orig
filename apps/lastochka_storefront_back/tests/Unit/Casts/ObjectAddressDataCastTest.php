<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\ObjectAddressDataCast;
use App\DTOs\ObjectAddressData;
use App\Models\Address;
use PHPUnit\Framework\TestCase;

final class ObjectAddressDataCastTest extends TestCase
{
    public function test_get_returns_null_for_null_value(): void
    {
        $cast = new ObjectAddressDataCast();
        $result = $cast->get(new Address(), 'object_data', null, []);

        $this->assertNull($result);
    }

    public function test_get_returns_dto_from_json_string(): void
    {
        $cast = new ObjectAddressDataCast();
        $json = json_encode([
            'name' => 'Многоэтажка',
            'contact_name' => 'Иванов',
            'has_lift' => true,
        ]);

        $result = $cast->get(new Address(), 'object_data', $json, []);

        $this->assertInstanceOf(ObjectAddressData::class, $result);
        $this->assertSame('Многоэтажка', $result->name);
        $this->assertSame('Иванов', $result->contactName);
        $this->assertTrue($result->hasLift);
    }

    public function test_set_serializes_dto_to_json_string(): void
    {
        $cast = new ObjectAddressDataCast();
        $dto = new ObjectAddressData(name: 'Объект', hasLift: true);

        $result = $cast->set(new Address(), 'object_data', $dto, []);

        $this->assertIsString($result['object_data']);
        $decoded = json_decode($result['object_data'], true);
        $this->assertSame('Объект', $decoded['name']);
        $this->assertTrue($decoded['has_lift']);
    }

    public function test_set_accepts_array_payload(): void
    {
        $cast = new ObjectAddressDataCast();

        $result = $cast->set(new Address(), 'object_data', ['name' => 'Объект', 'has_lift' => true], []);

        $decoded = json_decode($result['object_data'], true);
        $this->assertSame('Объект', $decoded['name']);
        $this->assertTrue($decoded['has_lift']);
    }

    public function test_get_returns_dto_from_pre_decoded_array(): void
    {
        $cast = new ObjectAddressDataCast();

        $result = $cast->get(new Address(), 'object_data', ['name' => 'Объект', 'has_lift' => true], []);

        $this->assertInstanceOf(ObjectAddressData::class, $result);
        $this->assertSame('Объект', $result->name);
        $this->assertTrue($result->hasLift);
    }

    public function test_get_returns_null_when_name_is_empty(): void
    {
        $cast = new ObjectAddressDataCast();

        $result = $cast->get(new Address(), 'object_data', json_encode(['name' => '', 'has_lift' => true]), []);

        $this->assertNull($result);
    }

    public function test_set_array_without_name_throws(): void
    {
        $cast = new ObjectAddressDataCast();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'name' is required");

        $cast->set(new Address(), 'object_data', ['has_lift' => true], []);
    }

    public function test_set_null_returns_null(): void
    {
        $cast = new ObjectAddressDataCast();
        $result = $cast->set(new Address(), 'object_data', null, []);

        $this->assertNull($result['object_data']);
    }
}
