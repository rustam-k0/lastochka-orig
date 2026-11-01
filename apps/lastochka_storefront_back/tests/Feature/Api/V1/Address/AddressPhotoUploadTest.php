<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Address;

use App\Models\Address;
use App\Models\AddressPhoto;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AddressPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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

    public function test_uploads_single_photo(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [UploadedFile::fake()->image('photo.jpg', 800, 600)],
        ]);

        $response->assertCreated()
            ->assertJsonCount(1, 'data.objectData.photos');

        $this->assertDatabaseCount('address_photos', 1);
        Storage::disk('public')->assertExists(AddressPhoto::first()->path);
    }

    public function test_uploads_batch_photos(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.png'),
                UploadedFile::fake()->image('c.jpg'),
            ],
        ]);

        $response->assertCreated()->assertJsonCount(3, 'data.objectData.photos');
        $this->assertDatabaseCount('address_photos', 3);
    }

    public function test_rejects_when_total_exceeds_6(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();
        AddressPhoto::factory()->count(5)->for($address)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('address_photos', 5);
    }

    public function test_rejects_invalid_mime(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['photos.0']);
    }

    public function test_rejects_oversized_file(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg')],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['photos.0']);
    }

    public function test_delete_photo_removes_record_and_file(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $this->actingAs($user)->postJson("/api/v1/addresses/{$address->id}/photos", [
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ]);
        $photo = AddressPhoto::first();
        $path = $photo->path;

        $delete = $this->actingAs($user)->deleteJson("/api/v1/addresses/{$address->id}/photos/{$photo->id}");

        $delete->assertNoContent();
        $this->assertSoftDeleted('address_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_delete_photo_from_other_address_returns_404(): void
    {
        $user = User::factory()->create();
        $myAddress = Address::factory()->object()->for($user)->create();
        $otherAddress = Address::factory()->object()->for($user)->create();
        $otherPhoto = AddressPhoto::factory()->for($otherAddress)->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/addresses/{$myAddress->id}/photos/{$otherPhoto->id}");

        $response->assertNotFound();
    }
}
