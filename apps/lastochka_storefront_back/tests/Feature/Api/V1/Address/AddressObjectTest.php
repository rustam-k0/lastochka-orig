<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Address;

use App\Models\AddressPhoto;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddressObjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    ModuleConfigService::ADDRESS_OBJECTS_KEY => ['type' => 'boolean', 'enabled' => true],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        Cache::flush();
    }

    public function test_creates_object_with_required_name_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => ['name' => 'Многоэтажка'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.isObject', true)
            ->assertJsonPath('data.objectData.name', 'Многоэтажка')
            ->assertJsonPath('data.objectData.contactName', null);
    }

    public function test_creates_full_object(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => [
                'name' => 'Многоэтажка',
                'contactName' => 'Иванов И. И.',
                'contactPhone' => '+79991234567',
                'company' => 'Звёздочка',
                'liftSize' => '1,5 на 2',
                'hasLift' => true,
                'needsPass' => false,
                'canOversized' => true,
                'workingHoursFrom' => '10:00',
                'workingHoursTo' => '20:00',
                'is247' => false,
                'workingDays' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                'isDaily' => false,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.objectData.contactName', 'Иванов И. И.')
            ->assertJsonPath('data.objectData.hasLift', true)
            ->assertJsonPath('data.objectData.workingDays', ['mon', 'tue', 'wed', 'thu', 'fri']);
    }

    public function test_validation_fails_when_object_without_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['objectData.name']);
    }

    public function test_invalid_phone_format_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => ['name' => 'Объект', 'contactPhone' => 'abc'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['objectData.contactPhone']);
    }

    public function test_invalid_working_day_code_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => ['name' => 'Объект', 'workingDays' => ['monday']],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['objectData.workingDays.0']);
    }

    public function test_24_7_persists_with_null_hours(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => [
                'name' => 'Объект',
                'is247' => true,
                'workingHoursFrom' => '10:00',
                'workingHoursTo' => '20:00',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.objectData.is247', true)
            ->assertJsonPath('data.objectData.workingHoursFrom', null)
            ->assertJsonPath('data.objectData.workingHoursTo', null);
    }

    public function test_daily_persists_with_empty_days(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'isObject' => true,
            'objectData' => [
                'name' => 'Объект',
                'isDaily' => true,
                'workingDays' => ['mon'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.objectData.isDaily', true)
            ->assertJsonPath('data.objectData.workingDays', []);
    }

    public function test_order_address_resource_returns_object_data_for_object_address(): void
    {
        Storage::fake('public');
        $user = \App\Models\User::factory()->create();

        $order = \App\Models\Order::factory()->for($user)->create();
        $orderAddr = $order->address()->create([
            'country' => 'Россия',
            'city' => 'Каспийск',
            'street' => 'ул. Горького',
            'house_number' => '78А',
            'entrance' => '3',
            'floor' => '10',
            'apartment' => '123',
            'comment' => null,
            'full_address' => 'Россия, Каспийск, ул. Горького, 78А, кв. 123',
            'latitude' => 42.89,
            'longitude' => 47.63,
            'object_data' => [
                'name' => 'Многоэтажка',
                'contact_name' => 'Иванов И.И.',
                'has_lift' => true,
                'is_24_7' => false,
                'working_days' => ['mon', 'tue'],
                'is_daily' => false,
                'photos' => [
                    ['url' => 'https://example/a.jpg', 'mime' => 'image/jpeg'],
                ],
            ],
        ]);

        $resource = new \App\Http\Resources\Api\V1\OrderAddressResource($orderAddr->fresh());
        $arr = $resource->toArray(request());

        $this->assertSame('Многоэтажка', $arr['objectData']['name']);
        $this->assertSame('Иванов И.И.', $arr['objectData']['contactName']);
        $this->assertTrue($arr['objectData']['hasLift']);
        $this->assertSame(['mon', 'tue'], $arr['objectData']['workingDays']);
        $this->assertCount(1, $arr['objectData']['photos']);
    }

    public function test_update_disables_is_object_clears_data_and_photos(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $address = \App\Models\Address::factory()->object()->for($user)->create();

        // Загрузить пару фото
        $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('address_photos', 2);

        $photos = AddressPhoto::where('address_id', $address->id)->get();
        $paths = $photos->pluck('path')->all();

        // Выключить is_object через PUT
        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}", [
            'country' => 'Россия',
            'city' => 'Каспийск',
            'isObject' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.isObject', false)
            ->assertJsonPath('data.objectData', null);

        // Проверка БД: address.object_data = null, is_object = false
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'is_object' => false,
            'object_data' => null,
        ]);

        // Фото soft-deleted — не видны без withTrashed
        $this->assertSame(
            0,
            AddressPhoto::where('address_id', $address->id)->count(),
            'photos should be soft-deleted (not visible without withTrashed)'
        );

        // Soft-deleted записи существуют
        $this->assertSame(
            2,
            AddressPhoto::withTrashed()->where('address_id', $address->id)->count()
        );

        // Проверка диска: файлы физически удалены
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }
}
