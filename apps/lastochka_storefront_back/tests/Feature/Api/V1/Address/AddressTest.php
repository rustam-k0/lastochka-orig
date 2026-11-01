<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Address;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AddressTest extends TestCase
{
    use RefreshDatabase;

    // ==================== Get Addresses ====================

    public function test_get_addresses(): void
    {
        $user = User::factory()->create();
        $activeAddress = Address::factory()->active()->for($user)->create();
        $regularAddress = Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/addresses');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $activeAddress->id)
            ->assertJsonPath('data.0.isActive', true)
            ->assertJsonPath('data.1.id', $regularAddress->id);
    }

    public function test_get_addresses_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/addresses');

        $response->assertUnauthorized();
    }

    public function test_get_addresses_does_not_return_other_users_addresses(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Address::factory()->for($user)->create();
        Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/addresses');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ==================== Get Active Address ====================

    public function test_get_active_address(): void
    {
        $user = User::factory()->create();
        $activeAddress = Address::factory()->active()->for($user)->create();
        Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/addresses/active');

        $response->assertOk()
            ->assertJsonPath('data.id', $activeAddress->id)
            ->assertJsonPath('data.isActive', true);
    }

    public function test_get_active_address_returns_null_when_no_active(): void
    {
        $user = User::factory()->create();
        Address::factory()->for($user)->create(['is_active' => false]);

        $response = $this->actingAs($user)->getJson('/api/v1/addresses/active');

        $response->assertOk()
            ->assertJsonPath('data', null);
    }

    // ==================== Create Address ====================

    public function test_create_address(): void
    {
        $user = User::factory()->create();
        $previousActive = Address::factory()->active()->for($user)->create(); // Уже есть один активный

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Ленина',
            'houseNumber' => '10',
            'entrance' => '2',
            'floor' => '5',
            'apartment' => '42',
            'intercom' => '42',
            'comment' => 'Домофон не работает',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.country', 'Россия')
            ->assertJsonPath('data.city', 'Москва')
            ->assertJsonPath('data.street', 'Ленина')
            ->assertJsonPath('data.houseNumber', '10')
            ->assertJsonPath('data.entrance', '2')
            ->assertJsonPath('data.floor', '5')
            ->assertJsonPath('data.apartment', '42')
            ->assertJsonPath('data.intercom', '42')
            ->assertJsonPath('data.comment', 'Домофон не работает')
            ->assertJsonPath('data.isActive', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'city' => 'Москва',
            'street' => 'Ленина',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('addresses', [
            'id' => $previousActive->id,
            'is_active' => false,
        ]);

        $this->assertSame(1, Address::forUser($user)->active()->count());
    }

    public function test_create_first_address_is_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Ленина',
            'houseNumber' => '10',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.isActive', true);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_create_address_is_active_when_no_active_exists(): void
    {
        $user = User::factory()->create();
        Address::factory()->for($user)->create(['is_active' => false]);

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Ленина',
            'houseNumber' => '10',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.isActive', true);

        $this->assertSame(1, Address::forUser($user)->active()->count());
    }

    public function test_create_address_does_not_affect_other_users_active_address(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherActive = Address::factory()->active()->for($otherUser)->create();

        $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Ленина',
            'houseNumber' => '10',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ])->assertCreated();

        $this->assertDatabaseHas('addresses', [
            'id' => $otherActive->id,
            'is_active' => true,
        ]);
    }

    public function test_create_address_generates_full_address(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Ленина',
            'houseNumber' => '10',
            'apartment' => '42',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.fullAddress', 'Россия, Москва, Ленина, д. 10, кв. 42');
    }

    public function test_create_address_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['country', 'city', 'latitude', 'longitude']);
    }

    public function test_create_address_rejects_zero_coordinates(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/addresses', [
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Ленина',
            'houseNumber' => '10',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_update_address_rejects_zero_coordinates(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}", [
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_update_address_rejects_partial_coordinates(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}", [
            'latitude' => 55.7558,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
    }

    // ==================== Update Address ====================

    public function test_update_address(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'city' => 'Москва',
            'street' => 'Старая',
        ]);

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}", [
            'city' => 'Санкт-Петербург',
            'street' => 'Новая',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.city', 'Санкт-Петербург')
            ->assertJsonPath('data.street', 'Новая');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'city' => 'Санкт-Петербург',
            'street' => 'Новая',
        ]);
    }

    public function test_update_address_recalculates_full_address(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Старая',
            'house_number' => '1',
            'apartment' => null,
        ]);

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}", [
            'city' => 'Казань',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.fullAddress', 'Россия, Казань, Старая, д. 1');
    }

    public function test_update_other_user_address_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}", [
            'city' => 'Москва',
        ]);

        $response->assertForbidden();
    }

    public function test_update_nonexistent_address_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson('/api/v1/addresses/99999', [
            'city' => 'Москва',
        ]);

        $response->assertNotFound();
    }

    // ==================== Delete Address ====================

    public function test_delete_address(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/addresses/{$address->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('addresses', [
            'id' => $address->id,
        ]);
    }

    public function test_delete_active_address_removes_active_flag(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->active()->for($user)->create();

        $this->assertTrue($address->is_active);

        $response = $this->actingAs($user)->deleteJson("/api/v1/addresses/{$address->id}");

        $response->assertNoContent();

        // Проверяем, что флаг снят перед удалением
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'is_active' => false,
        ]);
    }

    public function test_delete_other_user_address_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/addresses/{$address->id}");

        $response->assertForbidden();
    }

    // ==================== Set Active Address ====================

    public function test_set_active_address(): void
    {
        $user = User::factory()->create();
        $currentActive = Address::factory()->active()->for($user)->create();
        $newActive = Address::factory()->for($user)->create();

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$newActive->id}/active");

        $response->assertOk()
            ->assertJsonPath('data.id', $newActive->id)
            ->assertJsonPath('data.isActive', true);

        // Проверяем, что старый активный стал неактивным
        $this->assertDatabaseHas('addresses', [
            'id' => $currentActive->id,
            'is_active' => false,
        ]);

        // Проверяем, что новый стал активным
        $this->assertDatabaseHas('addresses', [
            'id' => $newActive->id,
            'is_active' => true,
        ]);
    }

    /**
     * Повторная активация уже активного адреса не должна его выключать:
     * раньше bulk-деактивация снимала флаг, а save() не-dirty модели его не возвращал.
     */
    public function test_set_active_already_active_address_keeps_it_active(): void
    {
        $user = User::factory()->create();
        $active = Address::factory()->active()->for($user)->create();
        Address::factory()->for($user)->create();

        $this->actingAs($user)
            ->putJson("/api/v1/addresses/{$active->id}/active")
            ->assertOk()
            ->assertJsonPath('data.isActive', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $active->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/addresses/active')
            ->assertOk()
            ->assertJsonPath('data.id', $active->id);
    }

    public function test_set_active_other_user_address_forbidden(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->putJson("/api/v1/addresses/{$address->id}/active");

        $response->assertForbidden();
    }

    public function test_set_active_only_one_active_per_user(): void
    {
        $user = User::factory()->create();

        // Создаём 3 адреса
        $address1 = Address::factory()->active()->for($user)->create();
        $address2 = Address::factory()->for($user)->create();
        $address3 = Address::factory()->for($user)->create();

        // Делаем активным второй
        $this->actingAs($user)->putJson("/api/v1/addresses/{$address2->id}/active");

        // Делаем активным третий
        $this->actingAs($user)->putJson("/api/v1/addresses/{$address3->id}/active");

        // Проверяем, что только третий активен
        $this->assertEquals(1, Address::forUser($user)->active()->count());
        $this->assertTrue(Address::find($address3->id)->is_active);
        $this->assertFalse(Address::find($address1->id)->is_active);
        $this->assertFalse(Address::find($address2->id)->is_active);
    }
}
