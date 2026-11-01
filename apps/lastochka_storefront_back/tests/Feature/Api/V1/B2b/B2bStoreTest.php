<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\B2b;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesB2bModule;
use Tests\TestCase;

final class B2bStoreTest extends TestCase
{
    use RefreshDatabase;
    use TogglesB2bModule;

    protected function tearDown(): void
    {
        $this->invalidateB2bModuleCache();
        parent::tearDown();
    }

    public function test_b2b_client_sees_all_stores_b2b_first(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();

        Store::factory()->create(['name' => 'Обычный магазин']); // B2C
        Store::factory()->b2b()->create(['name' => 'B2B Склад']);

        $response = $this->actingAs($user)->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'B2B Склад')
            ->assertJsonPath('data.0.isB2b', true)
            ->assertJsonPath('data.1.name', 'Обычный магазин')
            ->assertJsonPath('data.1.isB2b', false);
    }

    public function test_b2c_client_does_not_see_b2b_stores(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C

        Store::factory()->b2b()->create(['name' => 'B2B Склад']);
        Store::factory()->create(['name' => 'Обычный магазин']);

        $response = $this->actingAs($user)->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Обычный магазин')
            ->assertJsonPath('data.0.isB2b', false);
    }

    public function test_guest_sees_only_b2c_stores(): void
    {
        $this->setB2bModule(true);

        Store::factory()->b2b()->create(['name' => 'B2B Склад']);
        Store::factory()->create(['name' => 'Обычный магазин']);

        $response = $this->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Обычный магазин')
            ->assertJsonPath('data.0.isB2b', false);
    }

    public function test_module_disabled_guest_sees_all_stores(): void
    {
        $this->setB2bModule(false);

        Store::factory()->b2b()->create(['name' => 'B2B Склад']);
        Store::factory()->create(['name' => 'Обычный магазин']);

        $response = $this->getJson('/api/v1/stores');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_b2b_client_can_view_b2b_store_details(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->b2b()->create();

        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.isB2b', true);
    }

    public function test_b2b_client_can_view_b2c_store_details(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $store = Store::factory()->create(); // B2C

        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}");

        $response->assertOk()
            ->assertJsonPath('data.isB2b', false);
    }

    public function test_b2c_client_cannot_view_b2b_store_details(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $store = Store::factory()->b2b()->create();

        $response = $this->actingAs($user)->getJson("/api/v1/stores/{$store->id}");

        $response->assertNotFound();
    }

    public function test_guest_cannot_view_b2b_store_details(): void
    {
        $this->setB2bModule(true);

        $store = Store::factory()->b2b()->create();

        $response = $this->getJson("/api/v1/stores/{$store->id}");

        $response->assertNotFound();
    }
}
