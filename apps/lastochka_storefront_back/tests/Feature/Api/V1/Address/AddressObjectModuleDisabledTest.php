<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Address;

use App\Models\Address;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddressObjectModuleDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    ModuleConfigService::ADDRESS_OBJECTS_KEY => ['type' => 'boolean', 'enabled' => false],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        Cache::flush();
    }

    public function test_isObject_forced_false_when_module_disabled(): void
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
            ->assertJsonPath('data.isObject', false)
            ->assertJsonPath('data.objectData', null);

        $this->assertDatabaseHas('addresses', ['user_id' => $user->id, 'is_object' => false]);
    }

    public function test_objectData_stripped_from_resource_when_disabled(): void
    {
        $user = User::factory()->create();
        Address::factory()->object()->for($user)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/addresses');

        $response->assertOk()
            ->assertJsonPath('data.0.objectData', null);
    }

    public function test_photo_upload_returns_404_when_disabled(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $response->assertNotFound();
    }
}
