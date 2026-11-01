<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Delivery;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AddressPickupTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'cdek',
            'externalId' => 'MKHCH19',
            'title' => 'ПВЗ на Гамидова',
            'country' => 'Россия',
            'city' => 'Махачкала',
            'postalCode' => '367000',
            'street' => 'ул. Гамидова, 42',
            'lat' => 42.98,
            'lng' => 47.50,
            'phone' => '+79641234567',
            'raw' => ['type' => 'PVZ', 'workTime' => '09:00-18:00'],
        ], $overrides);
    }

    public function test_stores_cdek_pickup_address(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload()
        );

        $response->assertCreated()
            ->assertJsonPath('data.city', 'Махачкала')
            ->assertJsonPath('data.houseNumber', 'MKHCH19');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'city' => 'Махачкала',
            'house_number' => 'MKHCH19',
        ]);
    }

    public function test_stores_russian_post_pickup_address(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload([
                'provider' => 'russian_post',
                'externalId' => '367000',
                'title' => 'Почта России 367000',
                'street' => 'пр. Гамидова, 33',
            ])
        );

        $response->assertCreated()
            ->assertJsonPath('data.city', 'Махачкала');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'house_number' => '367000',
        ]);
    }

    public function test_requires_auth(): void
    {
        $response = $this->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload()
        );

        $response->assertUnauthorized();
    }

    public function test_validates_provider(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload(['provider' => 'yandex'])
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);
    }

    public function test_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            []
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['provider', 'externalId', 'country', 'city', 'street', 'lat', 'lng']);
    }

    public function test_validates_lat_lng_range(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload(['lat' => 999, 'lng' => -999])
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_address_is_not_active_by_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload()
        );

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'is_active' => false,
        ]);
    }

    public function test_additional_field_contains_pickup_metadata(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload()
        );

        $address = $user->fresh()->addresses()->first();

        $this->assertNotNull($address->additional);
        $this->assertEquals('pickup', $address->additional['kind']);
        $this->assertEquals('cdek', $address->additional['provider']);
        $this->assertEquals('MKHCH19', $address->additional['external_id']);
    }

    public function test_raw_is_nullable(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(
            '/api/v1/customer/addresses/pickup',
            $this->validPayload(['raw' => null])
        );

        $response->assertCreated();
    }
}
