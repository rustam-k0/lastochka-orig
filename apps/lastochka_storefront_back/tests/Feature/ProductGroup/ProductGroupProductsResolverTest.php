<?php

declare(strict_types=1);

namespace Tests\Feature\ProductGroup;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductView;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\ProductGroup\ProductGroupProductsResolver;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Правила автоматических товарных групп (раздел 2 спеки
 * 2026-10-08-auto-product-groups-design): каждая ветка резолвера.
 */
final class ProductGroupProductsResolverTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $user;

    private ProductGroupProductsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->store = Store::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create();
        $this->resolver = app(ProductGroupProductsResolver::class);
    }

    // ==================== Общие правила ====================

    public function test_manual_group_returns_group_relation(): void
    {
        $group = $this->group('manual');
        $product = $this->product();
        $group->items()->create(['product_id' => $product->id, 'position' => 0, 'quantity' => 1]);

        $this->assertSame([$product->id], $this->ids($group, $this->user));
        $this->assertSame([$product->id], $this->ids($group, null));
        $this->assertTrue($this->resolver->isVisibleFor($group, null));
    }

    public function test_unknown_fill_type_is_treated_as_manual(): void
    {
        $group = $this->group('something_new');

        $this->assertSame('manual', $group->fillType()->value);
        $this->assertTrue($this->resolver->isVisibleFor($group, null));
    }

    public function test_personal_groups_are_not_given_to_guest(): void
    {
        foreach (['purchase_history', 'viewed_not_bought'] as $type) {
            $group = $this->group($type);

            $this->assertNull($this->resolver->query($group, null), $type);
            $this->assertFalse($this->resolver->isVisibleFor($group, null), $type);
        }
    }

    public function test_personal_group_needs_three_products(): void
    {
        $group = $this->group('purchase_history');
        $a = $this->product();
        $b = $this->product();

        $this->deliveredOrder([$a, $b]);
        $this->assertFalse($this->resolver->isVisibleFor($group, $this->user));

        $this->deliveredOrder([$this->product()]);
        $this->assertTrue($this->resolver->isVisibleFor($group, $this->user));
    }

    public function test_personal_threshold_respects_config(): void
    {
        config(['product_groups.personal_min_products' => 1]);
        $group = $this->group('purchase_history');
        $this->deliveredOrder([$this->product()]);

        $this->assertTrue($this->resolver->isVisibleFor($group, $this->user));
    }

    public function test_personal_threshold_counts_only_adult_visible_products(): void
    {
        $group = $this->group('purchase_history');
        $this->deliveredOrder([$this->product(), $this->product(), $this->product(['adult_only' => true])]);

        $this->assertFalse($this->resolver->isVisibleFor($group, $this->user));

        $this->user->update(['adult_confirmed' => true]);
        $this->assertTrue($this->resolver->isVisibleFor($group, $this->user->fresh()));
    }

    public function test_general_auto_groups_have_no_threshold(): void
    {
        $group = $this->group('is_new');
        $this->product(['is_new' => true]);

        $this->assertTrue($this->resolver->isVisibleFor($group, null));
    }

    // ==================== Новинки / Успей купить ====================

    public function test_is_new_group_returns_flagged_products_newest_first(): void
    {
        $group = $this->group('is_new');
        $old = $this->product(['is_new' => true]);
        $this->product();
        $this->product(['is_hurry_up' => true]);
        $fresh = $this->product(['is_new' => true]);

        $this->assertSame([$fresh->id, $old->id], $this->ids($group, null));
    }

    public function test_is_hurry_up_group_returns_flagged_products(): void
    {
        $group = $this->group('is_hurry_up');
        $this->product(['is_new' => true]);
        $hurry = $this->product(['is_hurry_up' => true]);

        $this->assertSame([$hurry->id], $this->ids($group, $this->user));
    }

    public function test_auto_group_excludes_other_store_and_not_purchasable_products(): void
    {
        $group = $this->group('is_new');
        $own = $this->product(['is_new' => true]);
        $otherStore = Store::factory()->create(['is_active' => true]);
        $this->product(['is_new' => true, 'store_id' => $otherStore->id]);
        $this->product(['is_new' => true, 'is_active' => false]);
        $this->product(['is_new' => true, 'quantity' => 0]);
        $this->product(['is_new' => true, 'price' => 0]);

        $this->assertSame([$own->id], $this->ids($group, null));
    }

    // ==================== Из истории заказов ====================

    public function test_purchase_history_takes_only_delivered_orders(): void
    {
        $group = $this->group('purchase_history');
        $delivered = $this->product();
        $cancelled = $this->product();
        $pending = $this->product();

        $this->deliveredOrder([$delivered]);
        $this->order(OrderStatus::Cancelled, [$cancelled]);
        $this->order(OrderStatus::InDelivery, [$pending]);

        $this->assertSame([$delivered->id], $this->ids($group, $this->user));
    }

    public function test_purchase_history_item_statuses(): void
    {
        $group = $this->group('purchase_history');
        $nullStatus = $this->product();
        $added = $this->product();
        $confirmed = $this->product();
        $cancelled = $this->product();
        $replaced = $this->product();
        $replacement = $this->product();

        $order = $this->deliveredOrder([]);
        $this->item($order, $nullStatus, ['status' => null]);
        $this->item($order, $added, ['status' => 'added']);
        $this->item($order, $confirmed, ['status' => 'confirmed']);
        $this->item($order, $cancelled, ['status' => 'cancelled']);
        // Замена: исходный товар — replaced, сам заменитель — отдельная строка added
        $this->item($order, $replaced, ['status' => 'replaced']);
        $this->item($order, $replacement, ['status' => 'added', 'origin_product_id' => $replaced->id]);

        $ids = $this->ids($group, $this->user);
        sort($ids);
        $expected = [$nullStatus->id, $added->id, $confirmed->id, $replacement->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_purchase_history_full_return_excluded_partial_return_kept(): void
    {
        $group = $this->group('purchase_history');
        $full = $this->product();
        $partial = $this->product();

        // quantity позиции после доставки — уже доставленное (курьерка шлёт его
        // за вычетом возврата), returned_quantity — справочно (прод: заказ 141).
        $order = $this->deliveredOrder([]);
        $this->item($order, $full, ['quantity' => 0, 'returned_quantity' => 2]);
        $this->item($order, $partial, ['quantity' => 2, 'returned_quantity' => 1]);

        $this->assertSame([$partial->id], $this->ids($group, $this->user));
    }

    public function test_purchase_history_keeps_item_with_more_returned_than_delivered(): void
    {
        // Прод, заказ 886: заказано 3, вернули 2, доставлен 1 → quantity 1,
        // returned_quantity 2. Вычитание возврата второй раз (1 − 2 < 0)
        // выкидывало полученный товар из «Из истории заказов».
        $group = $this->group('purchase_history');
        $product = $this->product();

        $order = $this->deliveredOrder([]);
        $this->item($order, $product, ['quantity' => 1, 'returned_quantity' => 2]);

        $this->assertSame([$product->id], $this->ids($group, $this->user));
    }

    public function test_purchase_history_one_row_per_product_latest_delivery_first(): void
    {
        $group = $this->group('purchase_history');
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();

        $this->deliveredOrder([$a, $b], now()->subDays(10));
        $this->deliveredOrder([$b], now()->subDay());
        $this->deliveredOrder([$c], now()->subDays(5));
        // delivered_at не заполнен (старый заказ) — сортировка по updated_at
        $legacy = $this->deliveredOrder([$a], null);
        Order::query()->whereKey($legacy->id)->update(['updated_at' => now()->subDays(3)]);

        $this->assertSame([$b->id, $a->id, $c->id], $this->ids($group, $this->user));
    }

    public function test_purchase_history_ignores_other_users_orders(): void
    {
        $group = $this->group('purchase_history');
        $mine = $this->product();
        $foreign = $this->product();

        $this->deliveredOrder([$mine]);
        Order::factory()->for(User::factory())->for($this->store)->create(['status' => OrderStatus::Delivered])
            ->items()->create($this->itemAttributes($foreign));

        $this->assertSame([$mine->id], $this->ids($group, $this->user));
    }

    public function test_purchase_history_limited_to_group_store(): void
    {
        $group = $this->group('purchase_history');
        $otherStore = Store::factory()->create(['is_active' => true]);
        $own = $this->product();
        $foreign = $this->product(['store_id' => $otherStore->id]);

        $this->deliveredOrder([$own, $foreign]);

        $this->assertSame([$own->id], $this->ids($group, $this->user));
    }

    // ==================== Смотрел, но не купил ====================

    public function test_viewed_not_bought_basic_and_ttl(): void
    {
        $group = $this->group('viewed_not_bought');
        $fresh = $this->product();
        $old = $this->product();
        $boundary = $this->product();

        $this->viewed($fresh, ['last_viewed_at' => now()->subDay()]);
        $this->viewed($old, ['last_viewed_at' => now()->subDays(15)]);
        $this->viewed($boundary, ['last_viewed_at' => now()->subDays(14)->addMinute()]);

        $ids = $this->ids($group, $this->user);
        sort($ids);
        $expected = [$fresh->id, $boundary->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_viewed_not_bought_excludes_received_products(): void
    {
        $group = $this->group('viewed_not_bought');
        $bought = $this->product();
        $boughtButCancelledItem = $this->product();
        $boughtInCancelledOrder = $this->product();
        $returned = $this->product();

        foreach ([$bought, $boughtButCancelledItem, $boughtInCancelledOrder, $returned] as $p) {
            $this->viewed($p);
        }

        $order = $this->deliveredOrder([$bought]);
        $this->item($order, $boughtButCancelledItem, ['status' => 'cancelled']);
        $this->item($order, $returned, ['quantity' => 0, 'returned_quantity' => 1]);
        $this->order(OrderStatus::Cancelled, [$boughtInCancelledOrder]);

        $ids = $this->ids($group, $this->user);
        sort($ids);
        $expected = [$boughtButCancelledItem->id, $boughtInCancelledOrder->id, $returned->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_viewed_not_bought_excludes_products_in_cart(): void
    {
        $group = $this->group('viewed_not_bought');
        $inCart = $this->product();
        $notInCart = $this->product();
        $this->viewed($inCart);
        $this->viewed($notInCart);

        $cart = Cart::factory()->for($this->user)->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $inCart->id, 'store_id' => $this->store->id]);

        // Чужая корзина не влияет
        $otherCart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $otherCart->id, 'product_id' => $notInCart->id, 'store_id' => $this->store->id]);

        $this->assertSame([$notInCart->id], $this->ids($group, $this->user));
    }

    public function test_viewed_not_bought_ignores_other_users_views_and_other_store(): void
    {
        $group = $this->group('viewed_not_bought');
        $otherStore = Store::factory()->create(['is_active' => true]);
        $mine = $this->product();
        $foreignStore = $this->product(['store_id' => $otherStore->id]);
        $foreignView = $this->product();

        $this->viewed($mine);
        $this->viewed($foreignStore);
        ProductView::create([
            'user_id' => User::factory()->create()->id,
            'product_id' => $foreignView->id,
            'views_count' => 5,
            'first_viewed_at' => now(),
            'last_viewed_at' => now(),
        ]);

        $this->assertSame([$mine->id], $this->ids($group, $this->user));
    }

    public function test_viewed_not_bought_ranking(): void
    {
        $group = $this->group('viewed_not_bought');

        // score = views_count + 2·cart_removed + 2·favorite + 1·discount
        $plain = $this->product();                              // 1
        $manyViews = $this->product();                          // 3
        $cartRemoved = $this->product();                        // 1 + 2 = 3, но просмотрен позже manyViews
        $favorite = $this->product();                           // 2 + 2 = 4
        $manualDiscount = $this->product(['price_old' => 999]); // 1 + 1 = 2
        $promo = $this->product();                              // 1 + 1 = 2, просмотрен раньше manualDiscount
        $expiredPromo = $this->product();                       // 1

        $this->viewed($plain, ['last_viewed_at' => now()->subHours(1)]);
        $this->viewed($manyViews, ['views_count' => 3, 'last_viewed_at' => now()->subHours(5)]);
        $this->viewed($cartRemoved, ['cart_removed_at' => now(), 'last_viewed_at' => now()->subHours(2)]);
        $this->viewed($favorite, ['views_count' => 2, 'last_viewed_at' => now()->subHours(9)]);
        $this->viewed($manualDiscount, ['last_viewed_at' => now()->subHours(3)]);
        $this->viewed($promo, ['last_viewed_at' => now()->subHours(4)]);
        $this->viewed($expiredPromo, ['last_viewed_at' => now()->subHours(6)]);

        Favorite::create(['user_id' => $this->user->id, 'product_id' => $favorite->id]);
        Promotion::factory()->for($this->store)->create()->products()->attach($promo->id);
        Promotion::factory()->for($this->store)->expired()->create()->products()->attach($expiredPromo->id);

        $this->assertSame([
            $favorite->id,
            $cartRemoved->id,
            $manyViews->id,
            $manualDiscount->id,
            $promo->id,
            $plain->id,
            $expiredPromo->id,
        ], $this->ids($group, $this->user));
    }

    public function test_viewed_not_bought_ranking_weights_from_config(): void
    {
        config(['product_groups.ranking.favorite' => 0]);
        $group = $this->group('viewed_not_bought');
        $favorite = $this->product();
        $viewed = $this->product();

        $this->viewed($favorite, ['last_viewed_at' => now()->subHours(2)]);
        $this->viewed($viewed, ['views_count' => 2, 'last_viewed_at' => now()->subHours(3)]);
        Favorite::create(['user_id' => $this->user->id, 'product_id' => $favorite->id]);

        $this->assertSame([$viewed->id, $favorite->id], $this->ids($group, $this->user));
    }

    // ==================== Сетевой каталог ====================

    public function test_network_catalog_limits_auto_group_to_group_store_offers(): void
    {
        $this->setNetworkCatalog(true);
        $otherStore = Store::factory()->create(['is_active' => true]);
        $group = $this->group('viewed_not_bought');

        $here = $this->networkProduct($this->store, priceOld: 500);
        $elsewhere = $this->networkProduct($otherStore);
        $plain = $this->networkProduct($this->store);
        $plainBis = $this->networkProduct($this->store);

        $this->viewed($here, ['last_viewed_at' => now()->subHours(3)]);
        $this->viewed($elsewhere);
        $this->viewed($plain, ['last_viewed_at' => now()->subHour()]);
        $this->viewed($plainBis, ['last_viewed_at' => now()->subHours(2)]);

        // скидка оффера магазина группы поднимает товар наверх
        $this->assertSame([$here->id, $plain->id, $plainBis->id], $this->ids($group, $this->user));
        $this->assertTrue($this->resolver->isVisibleFor($group, $this->user));

        $isNew = $this->group('is_new');
        $flagged = $this->networkProduct($this->store, attributes: ['is_new' => true]);
        $this->networkProduct($otherStore, attributes: ['is_new' => true]);

        $this->assertSame([$flagged->id], $this->ids($isNew, null));
    }

    // ==================== Helpers ====================

    private function group(string $fillType): ProductGroup
    {
        return ProductGroup::factory()->for($this->store)->create(['fill_type' => $fillType]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'store_id' => $this->store->id,
            'price' => 100,
            'quantity' => 10,
            'quantity_step' => 1,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function networkProduct(Store $store, ?float $priceOld = null, array $attributes = []): Product
    {
        $product = $this->product(array_merge(['price' => 0, 'quantity' => 0], $attributes));

        StoreProduct::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'price' => 300,
            'price_old' => $priceOld,
            'quantity' => 5,
            'is_active' => true,
        ]);

        return $product;
    }

    /**
     * @param  array<int, Product>  $products
     */
    private function deliveredOrder(array $products, mixed $deliveredAt = 'now'): Order
    {
        return $this->order(OrderStatus::Delivered, $products, $deliveredAt === 'now' ? now() : $deliveredAt);
    }

    /**
     * @param  array<int, Product>  $products
     */
    private function order(OrderStatus $status, array $products, mixed $deliveredAt = null): Order
    {
        $order = Order::factory()->for($this->user)->for($this->store)->create([
            'status' => $status,
            'delivered_at' => $deliveredAt,
        ]);

        foreach ($products as $product) {
            $this->item($order, $product);
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(Order $order, Product $product, array $attributes = []): OrderItem
    {
        return $order->items()->create(array_merge($this->itemAttributes($product), $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(Product $product): array
    {
        return [
            'product_id' => $product->id,
            'title' => $product->title,
            'sku' => (string) $product->sku,
            'price' => 100,
            'quantity' => 1,
            'total' => 100,
            'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function viewed(Product $product, array $attributes = []): ProductView
    {
        return ProductView::create(array_merge([
            'user_id' => $this->user->id,
            'product_id' => $product->id,
            'views_count' => 1,
            'first_viewed_at' => now()->subDay(),
            'last_viewed_at' => now()->subHour(),
        ], $attributes));
    }

    /**
     * @return array<int, int>
     */
    private function ids(ProductGroup $group, ?User $user): array
    {
        return $this->resolver->query($group, $user)
            ->get()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function setNetworkCatalog(bool $enabled): void
    {
        StorefrontSetting::updateOrCreate(
            ['key' => ModuleConfigService::PROJECT_MODULES_KEY],
            [
                'value' => json_encode([
                    'network_catalog' => ['label' => 'Сетевой каталог', 'enabled' => $enabled],
                ], JSON_UNESCAPED_UNICODE),
                'type' => 'json',
            ]
        );

        app(ModuleConfigService::class)->invalidateCache();
    }
}
