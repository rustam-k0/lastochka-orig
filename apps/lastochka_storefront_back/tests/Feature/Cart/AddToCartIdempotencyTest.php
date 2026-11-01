<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class AddToCartIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 1000,
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ]);
    }

    public function test_retry_with_same_key_does_not_double_quantity(): void
    {
        $first = $this->addItem(2, 'key-1');
        $second = $this->addItem(2, 'key-1');

        $first->assertOk();
        $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame(2, $this->cartQuantity());
    }

    public function test_different_keys_add_quantity_as_before(): void
    {
        $this->addItem(2, 'key-1')->assertOk();
        $this->addItem(2, 'key-2')->assertOk()->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(4, $this->cartQuantity());
    }

    public function test_without_key_behaviour_is_unchanged(): void
    {
        $this->addItem(2)->assertOk();
        $this->addItem(2)->assertOk();

        $this->assertSame(4, $this->cartQuantity());
    }

    public function test_same_key_with_different_body_is_rejected(): void
    {
        $this->addItem(2, 'key-1')->assertOk();

        $this->addItem(3, 'key-1')
            ->assertStatus(422)
            ->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSED']);

        $this->assertSame(2, $this->cartQuantity());
    }

    public function test_key_is_scoped_per_user(): void
    {
        $this->addItem(2, 'shared-key')->assertOk();

        $other = User::factory()->create();
        $this->actingAs($other)
            ->withHeader('Idempotency-Key', 'shared-key')
            ->postJson('/api/v1/cart/items', ['productId' => $this->product->id, 'quantity' => 2])
            ->assertOk()
            ->assertHeaderMissing('Idempotency-Replayed');

        $this->assertSame(2, $this->cartQuantity());
        $this->assertSame(2, (int) CartItem::query()->whereHas('cart', fn ($q) => $q->where('user_id', $other->id))->sum('quantity'));
    }

    public function test_failed_request_is_not_stored_and_retry_executes(): void
    {
        $this->addItem(50, 'key-1')->assertStatus(422);

        $this->product->update(['quantity' => 100]);

        $this->addItem(50, 'key-1')->assertOk()->assertHeaderMissing('Idempotency-Replayed');
        $this->assertSame(50, $this->cartQuantity());
    }

    public function test_too_long_key_is_rejected(): void
    {
        $this->addItem(1, str_repeat('ж', 129))
            ->assertStatus(422)
            ->assertJson(['error' => 'IDEMPOTENCY_KEY_INVALID']);

        $this->assertSame(0, $this->cartQuantity());
    }

    public function test_concurrent_duplicate_gets_409_while_first_is_in_progress(): void
    {
        config(['idempotency.lock_wait_seconds' => 0]);

        $cacheKey = 'idempotency:'.implode(':', [
            $this->user->id,
            'POST',
            hash('sha256', 'api/v1/cart/items'),
            hash('sha256', 'key-busy'),
        ]);
        $lock = Cache::lock($cacheKey.':lock', 30);
        $this->assertTrue($lock->get());

        $this->addItem(1, 'key-busy')
            ->assertStatus(409)
            ->assertJson(['error' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS']);

        $lock->release();
        $this->assertSame(0, $this->cartQuantity());
    }

    public function test_batch_retry_with_same_key_does_not_double_quantity(): void
    {
        $payload = ['items' => [['productId' => $this->product->id, 'quantity' => 3]]];

        $this->actingAs($this->user)->withHeader('Idempotency-Key', 'batch-1')
            ->postJson('/api/v1/cart/items/batch', $payload)->assertOk();
        $this->actingAs($this->user)->withHeader('Idempotency-Key', 'batch-1')
            ->postJson('/api/v1/cart/items/batch', $payload)->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(3, $this->cartQuantity());
    }

    private function addItem(int $quantity, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $request = $this->actingAs($this->user);

        if ($key !== null) {
            $request = $request->withHeader('Idempotency-Key', $key);
        }

        return $request->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => $quantity,
        ]);
    }

    private function cartQuantity(): int
    {
        return (int) CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('user_id', $this->user->id))
            ->sum('quantity');
    }
}
