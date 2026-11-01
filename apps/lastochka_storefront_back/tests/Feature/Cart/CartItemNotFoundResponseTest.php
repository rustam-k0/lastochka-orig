<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartItemNotFoundResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_of_missing_cart_item_returns_code_and_russian_message(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->putJson('/api/v1/cart/items/9920', ['quantity' => 1]);

        $response->assertNotFound()
            ->assertExactJson([
                'error' => 'CART_ITEM_NOT_FOUND',
                'message' => 'Позиция уже удалена из корзины',
            ]);
        $this->assertStringNotContainsString('App\\\\Models', $response->getContent());
    }

    public function test_delete_of_missing_cart_item_returns_code_and_russian_message(): void
    {
        $this->actingAs(User::factory()->create())
            ->deleteJson('/api/v1/cart/items/9920')
            ->assertNotFound()
            ->assertExactJson([
                'error' => 'CART_ITEM_NOT_FOUND',
                'message' => 'Позиция уже удалена из корзины',
            ]);
    }

    public function test_other_missing_model_returns_generic_code_without_class_name(): void
    {
        $response = $this->getJson('/api/v1/products/999999');

        $response->assertNotFound()
            ->assertJson(['error' => 'NOT_FOUND']);
        $this->assertStringNotContainsString('No query results', $response->getContent());
    }

    public function test_unknown_route_is_not_affected(): void
    {
        $this->getJson('/api/v1/nonexistent-route-xyz')
            ->assertNotFound()
            ->assertJsonMissing(['error' => 'NOT_FOUND']);
    }
}
