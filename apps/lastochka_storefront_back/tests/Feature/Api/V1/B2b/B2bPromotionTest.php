<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\B2b;

use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TogglesB2bModule;
use Tests\TestCase;

final class B2bPromotionTest extends TestCase
{
    use RefreshDatabase;
    use TogglesB2bModule;

    protected function tearDown(): void
    {
        $this->invalidateB2bModuleCache();
        parent::tearDown();
    }

    public function test_b2c_client_does_not_see_b2b_store_promotion_in_list(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $b2bStore = Store::factory()->b2b()->create();
        $regularStore = Store::factory()->create();

        Promotion::factory()->for($b2bStore)->create(['title' => 'Акция Недели']);
        $regularPromotion = Promotion::factory()->for($regularStore)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/promotions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regularPromotion->id);
    }

    public function test_guest_does_not_see_b2b_store_promotion_in_list(): void
    {
        $this->setB2bModule(true);

        $b2bStore = Store::factory()->b2b()->create();
        $regularStore = Store::factory()->create();

        Promotion::factory()->for($b2bStore)->create();
        $regularPromotion = Promotion::factory()->for($regularStore)->create();

        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regularPromotion->id);
    }

    public function test_b2b_client_sees_b2b_store_promotion_in_list(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $b2bStore = Store::factory()->b2b()->create();
        $regularStore = Store::factory()->create();

        Promotion::factory()->for($b2bStore)->create();
        Promotion::factory()->for($regularStore)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/promotions');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_module_disabled_b2c_client_sees_b2b_store_promotion(): void
    {
        $this->setB2bModule(false);

        $user = User::factory()->create(); // B2C
        $b2bStore = Store::factory()->b2b()->create();

        Promotion::factory()->for($b2bStore)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/promotions');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_b2c_client_cannot_view_b2b_store_promotion_details(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $b2bStore = Store::factory()->b2b()->create();
        $promotion = Promotion::factory()->for($b2bStore)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertNotFound();
    }

    public function test_guest_cannot_view_b2b_store_promotion_details(): void
    {
        $this->setB2bModule(true);

        $b2bStore = Store::factory()->b2b()->create();
        $promotion = Promotion::factory()->for($b2bStore)->create();

        $response = $this->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertNotFound();
    }

    public function test_b2b_client_can_view_b2b_store_promotion_details(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->b2b()->create();
        $b2bStore = Store::factory()->b2b()->create();
        $promotion = Promotion::factory()->for($b2bStore)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertOk()->assertJsonPath('data.id', $promotion->id);
    }

    public function test_b2c_client_cannot_view_b2b_store_promotion_products(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $b2bStore = Store::factory()->b2b()->create();
        $promotion = Promotion::factory()->for($b2bStore)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertNotFound();
    }

    public function test_b2c_client_cannot_view_promotion_details_when_b2b_store_soft_deleted(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $b2bStore = Store::factory()->b2b()->create();
        $promotion = Promotion::factory()->for($b2bStore)->create();

        $b2bStore->delete(); // soft delete

        $response = $this->actingAs($user)->getJson('/api/v1/promotions/'.$promotion->slug);

        $response->assertNotFound();
    }

    public function test_b2c_client_cannot_view_promotion_products_when_b2b_store_soft_deleted(): void
    {
        $this->setB2bModule(true);

        $user = User::factory()->create(); // B2C
        $b2bStore = Store::factory()->b2b()->create();
        $promotion = Promotion::factory()->for($b2bStore)->create();

        $b2bStore->delete(); // soft delete

        $response = $this->actingAs($user)->getJson('/api/v1/promotions/'.$promotion->slug.'/products');

        $response->assertNotFound();
    }
}
