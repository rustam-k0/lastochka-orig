<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Catalog;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdultContentFilterTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Category $category;

    private Product $regular;

    private Product $adult;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();

        $this->regular = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'title' => 'Сок яблочный',
            'adult_only' => false,
        ]);
        $this->adult = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'title' => 'Вино красное',
            'adult_only' => true,
        ]);
    }

    private function catalogEndpoint(): string
    {
        return "/api/v1/stores/{$this->store->id}/categories/{$this->category->id}/products";
    }

    // ==================== Каталог: гость ====================

    public function test_guest_does_not_see_adult_products_by_default(): void
    {
        $response = $this->getJson($this->catalogEndpoint());

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->regular->id, $ids);
        $this->assertNotContains($this->adult->id, $ids);
    }

    public function test_guest_sees_adult_products_with_show_adult_param(): void
    {
        $response = $this->getJson($this->catalogEndpoint().'?showAdult=1');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->regular->id, $ids);
        $this->assertContains($this->adult->id, $ids);
    }

    // ==================== Каталог: авторизованный ====================

    public function test_unconfirmed_user_does_not_see_adult_even_with_param(): void
    {
        $user = User::factory()->create(['adult_confirmed' => false]);

        $response = $this->actingAs($user)->getJson($this->catalogEndpoint().'?showAdult=1');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->adult->id, $ids);
    }

    public function test_confirmed_user_sees_adult_without_param(): void
    {
        $user = User::factory()->create(['adult_confirmed' => true]);

        $response = $this->actingAs($user)->getJson($this->catalogEndpoint());

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->adult->id, $ids);
    }

    // ==================== Карточка товара не блокируется ====================

    public function test_product_card_returns_adult_product_to_guest(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->adult->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $this->adult->id)
            ->assertJsonPath('data.adultOnly', true);
    }

    // ==================== Избранное ====================

    public function test_favorites_hide_adult_for_unconfirmed_user(): void
    {
        $user = User::factory()->create(['adult_confirmed' => false]);
        Favorite::factory()->for($user)->for($this->adult)->create();
        Favorite::factory()->for($user)->for($this->regular)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->regular->id, $ids);
        $this->assertNotContains($this->adult->id, $ids);
    }

    public function test_favorites_show_adult_for_confirmed_user(): void
    {
        $user = User::factory()->create(['adult_confirmed' => true]);
        Favorite::factory()->for($user)->for($this->adult)->create();

        $response = $this->actingAs($user)->getJson('/api/v1/favorites');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->adult->id, $ids);
    }

    // ==================== Похожие товары ====================

    public function test_similar_hides_adult_for_guest(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->regular->id}/similar");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->adult->id, $ids);
    }

    public function test_similar_shows_adult_with_param(): void
    {
        $response = $this->getJson("/api/v1/products/{$this->regular->id}/similar?showAdult=1");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($this->adult->id, $ids);
    }

    // ==================== Auth: фиксация флага ====================

    public function test_phone_confirm_with_adult_confirmed_sets_flag(): void
    {
        $phone = '+79000000000';
        config(['services.auth.test_phones' => [$phone => '847291']]);

        $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => '847291',
            'adultConfirmed' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.adultConfirmed', true);

        $this->assertDatabaseHas('users', ['phone' => $phone, 'adult_confirmed' => true]);
    }

    public function test_phone_confirm_false_does_not_reset_flag(): void
    {
        $phone = '+79000000001';
        User::factory()->create(['phone' => $phone, 'adult_confirmed' => true]);
        config(['services.auth.test_phones' => [$phone => '847291']]);

        $this->postJson('/api/v1/auth/phone-login', ['phone' => $phone]);

        $response = $this->postJson('/api/v1/auth/phone-confirm', [
            'phone' => $phone,
            'code' => '847291',
            'adultConfirmed' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.adultConfirmed', true);
    }

    public function test_adult_confirm_endpoint_sets_and_unsets_flag(): void
    {
        $user = User::factory()->create(['adult_confirmed' => false]);

        $response = $this->actingAs($user)->postJson('/api/v1/auth/adult-confirm', ['confirmed' => true]);
        $response->assertOk()->assertJsonPath('data.adultConfirmed', true);
        $this->assertTrue($user->fresh()->adult_confirmed);

        $response = $this->actingAs($user)->postJson('/api/v1/auth/adult-confirm', ['confirmed' => false]);
        $response->assertOk()->assertJsonPath('data.adultConfirmed', false);
        $this->assertFalse($user->fresh()->adult_confirmed);
    }

    public function test_adult_confirm_requires_auth_and_validates(): void
    {
        $this->postJson('/api/v1/auth/adult-confirm', ['confirmed' => true])
            ->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/auth/adult-confirm', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmed']);
    }

    public function test_profile_returns_adult_confirmed(): void
    {
        $user = User::factory()->create(['adult_confirmed' => true]);

        $this->actingAs($user)->getJson('/api/v1/auth/profile')
            ->assertOk()
            ->assertJsonPath('data.adultConfirmed', true);
    }
}
