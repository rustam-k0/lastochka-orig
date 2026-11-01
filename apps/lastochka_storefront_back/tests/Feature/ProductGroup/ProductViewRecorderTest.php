<?php

declare(strict_types=1);

namespace Tests\Feature\ProductGroup;

use App\Actions\Cart\UpdateCartItemAction;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductView;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductGroup\ProductViewRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Запись просмотров (product_views) из product.viewed, отметка
 * «клал в корзину и убрал» и очистка product-views:prune.
 */
final class ProductViewRecorderTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    private Product $product;

    private ProductViewRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create();
        $this->product = Product::factory()->create([
            'store_id' => $this->store->id,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
        ]);
        $this->recorder = app(ProductViewRecorder::class);
    }

    // ==================== record() ====================

    public function test_records_view_and_increments_counter(): void
    {
        $first = now()->subHours(2)->startOfSecond();
        $second = now()->subHour()->startOfSecond();

        $this->assertTrue($this->recorder->record($this->user, $this->payload(['occurred_at' => $first->toIso8601String()])));
        $this->assertTrue($this->recorder->record($this->user, $this->payload(['occurred_at' => $second->toIso8601String()])));

        $view = ProductView::query()->where('user_id', $this->user->id)->where('product_id', $this->product->id)->sole();

        $this->assertSame(2, $view->views_count);
        $this->assertTrue($view->first_viewed_at->equalTo($first));
        $this->assertTrue($view->last_viewed_at->equalTo($second));
        $this->assertNull($view->cart_removed_at);
    }

    public function test_out_of_order_event_does_not_move_last_viewed_back(): void
    {
        $late = now()->subHour()->startOfSecond();
        $early = now()->subDays(2)->startOfSecond();

        $this->recorder->record($this->user, $this->payload(['occurred_at' => $late->toIso8601String()]));
        $this->recorder->record($this->user, $this->payload(['occurred_at' => $early->toIso8601String()]));

        $view = ProductView::query()->sole();
        $this->assertSame(2, $view->views_count);
        $this->assertTrue($view->last_viewed_at->equalTo($late));
        $this->assertTrue($view->first_viewed_at->equalTo($early));
    }

    public function test_short_view_is_not_recorded(): void
    {
        $this->assertFalse($this->recorder->record($this->user, $this->payload(['duration_ms' => 2999])));
        $this->assertTrue($this->recorder->record($this->user, $this->payload(['duration_ms' => 3000])));

        $this->assertSame(1, ProductView::query()->sole()->views_count);
    }

    public function test_duration_threshold_from_config(): void
    {
        config(['product_groups.personal_view_min_duration_ms' => 10000]);

        $this->assertFalse($this->recorder->record($this->user, $this->payload(['duration_ms' => 9000])));
        $this->assertSame(0, ProductView::query()->count());
    }

    public function test_view_without_duration_is_not_recorded(): void
    {
        $payload = $this->payload();
        unset($payload['duration_ms']);

        $this->assertFalse($this->recorder->record($this->user, $payload));
        $this->assertFalse($this->recorder->record($this->user, $this->payload(['duration_ms' => null])));
        $this->assertFalse($this->recorder->record($this->user, $this->payload(['duration_ms' => 'долго'])));
        $this->assertSame(0, ProductView::query()->count());
    }

    public function test_view_from_viewed_not_bought_group_is_not_recorded(): void
    {
        $own = ProductGroup::factory()->for($this->store)->create(['fill_type' => 'viewed_not_bought']);
        $other = ProductGroup::factory()->for($this->store)->create(['fill_type' => 'purchase_history']);

        $this->assertFalse($this->recorder->record($this->user, $this->payload([
            'entry_point' => 'product_group',
            'product_group_id' => $own->id,
        ])));
        $this->assertSame(0, ProductView::query()->count());

        // Из другой группы и из «Смотрел» без source=product_group — фиксируется
        $this->assertTrue($this->recorder->record($this->user, $this->payload([
            'entry_point' => 'product_group',
            'product_group_id' => $other->id,
        ])));
        $this->assertTrue($this->recorder->record($this->user, $this->payload([
            'entry_point' => 'catalog',
            'product_group_id' => $own->id,
        ])));
        $this->assertSame(2, ProductView::query()->sole()->views_count);
    }

    public function test_guest_view_is_not_recorded(): void
    {
        $this->assertFalse($this->recorder->record(null, $this->payload()));
        $this->assertSame(0, ProductView::query()->count());
    }

    public function test_unknown_or_invalid_product_is_not_recorded(): void
    {
        $this->assertFalse($this->recorder->record($this->user, $this->payload(['product_id' => 999999])));
        $this->assertFalse($this->recorder->record($this->user, $this->payload(['product_id' => 'abc'])));
        $this->assertFalse($this->recorder->record($this->user, $this->payload(['product_id' => null])));
        $this->assertSame(0, ProductView::query()->count());
    }

    // ==================== Ingest ====================

    public function test_ingest_records_view_for_authorized_user(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/analytics/events', ['events' => [
                $this->event(),
                $this->event(['duration_ms' => 500]),
                $this->event(['event_type' => 'category.viewed']),
            ]])
            ->assertStatus(202)
            ->assertJson(['accepted' => 3, 'rejected' => []]);

        $this->assertSame(1, ProductView::query()->where('user_id', $this->user->id)->sole()->views_count);
        $this->assertSame(3, DB::table('event_outbox')->where('entity_type', 'b2c_events')
            ->where('routing_key', '!=', 'b2c.identity.linked')->count());
    }

    public function test_ingest_does_not_record_guest_view(): void
    {
        $this->postJson('/api/v1/analytics/events', ['events' => [$this->event()]])
            ->assertStatus(202)
            ->assertJson(['accepted' => 1]);

        $this->assertSame(0, ProductView::query()->count());
    }

    public function test_ingest_survives_recorder_failure(): void
    {
        // Запись просмотра падает (нет таблицы) — приём события не страдает.
        // SQLite DDL транзакционный: RefreshDatabase вернёт таблицу откатом.
        Schema::drop('product_views');

        $token = $this->user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/analytics/events', ['events' => [$this->event()]])
            ->assertStatus(202)
            ->assertJson(['accepted' => 1, 'rejected' => []]);

        $this->assertSame(1, DB::table('event_outbox')->where('routing_key', 'b2c.product.viewed')->count());
    }

    // ==================== cart_removed_at ====================

    public function test_remove_cart_item_marks_existing_view(): void
    {
        $notViewed = Product::factory()->create(['store_id' => $this->store->id]);
        $this->viewed($this->product);
        $cart = Cart::factory()->for($this->user)->create();
        $item = CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'store_id' => $this->store->id]);
        $otherItem = CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $notViewed->id, 'store_id' => $this->store->id]);

        $this->actingAs($this->user)->deleteJson("/api/v1/cart/items/{$item->id}")->assertOk();
        $this->actingAs($this->user)->deleteJson("/api/v1/cart/items/{$otherItem->id}")->assertOk();

        $this->assertNotNull(ProductView::query()->where('product_id', $this->product->id)->sole()->cart_removed_at);
        // Новые строки не создаются
        $this->assertSame(0, ProductView::query()->where('product_id', $notViewed->id)->count());
    }

    public function test_clear_cart_marks_existing_views_of_this_user_only(): void
    {
        $other = User::factory()->create();
        $this->viewed($this->product);
        $this->viewed($this->product, $other);

        $cart = Cart::factory()->for($this->user)->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'store_id' => $this->store->id]);

        $this->actingAs($this->user)->deleteJson('/api/v1/cart')->assertNoContent();

        $this->assertNotNull(ProductView::query()->where('user_id', $this->user->id)->sole()->cart_removed_at);
        $this->assertNull(ProductView::query()->where('user_id', $other->id)->sole()->cart_removed_at);
    }

    public function test_update_cart_item_marks_view_only_when_quantity_drops_to_zero(): void
    {
        $this->viewed($this->product);
        $cart = Cart::factory()->for($this->user)->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'store_id' => $this->store->id,
            'quantity' => 3,
        ]);

        app(UpdateCartItemAction::class)->execute($item, 1);
        $this->assertNull(ProductView::query()->sole()->cart_removed_at);

        app(UpdateCartItemAction::class)->execute($item->fresh(), 0);
        $this->assertNotNull(ProductView::query()->sole()->cart_removed_at);
    }

    // ==================== prune ====================

    public function test_prune_command_removes_views_older_than_ttl(): void
    {
        $fresh = $this->viewed($this->product, null, now()->subDays(13));
        $old = $this->viewed(Product::factory()->create(['store_id' => $this->store->id]), null, now()->subDays(15));

        $this->artisan('product-views:prune')->assertSuccessful();

        $this->assertTrue(ProductView::query()->whereKey($fresh->id)->exists());
        $this->assertFalse(ProductView::query()->whereKey($old->id)->exists());
    }

    // ==================== Helpers ====================

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_type' => 'product.viewed',
            'occurred_at' => now()->subMinute()->toIso8601String(),
            'product_id' => $this->product->id,
            'duration_ms' => 8400,
            'entry_point' => 'catalog',
            'product_group_id' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'client_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
        ], $overrides));
    }

    private function viewed(Product $product, ?User $user = null, ?Carbon $lastViewedAt = null): ProductView
    {
        return ProductView::create([
            'user_id' => ($user ?? $this->user)->id,
            'product_id' => $product->id,
            'views_count' => 1,
            'first_viewed_at' => $lastViewedAt ?? now(),
            'last_viewed_at' => $lastViewedAt ?? now(),
        ]);
    }
}
