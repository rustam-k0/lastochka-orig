<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promocode;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\Models\Supplement;
use App\Models\SupplementValue;
use App\Models\User;
use App\Services\Settings\ModuleConfigService;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Переключение корзины на магазин (MID-204): POST /api/v1/cart/store.
 *
 * Клиент сменил адрес доставки или магазин самовывоза — позиции корзины
 * перепроверяются по ассортименту нового магазина:
 * (а) позиция с валидным оффером в целевом магазине перепривязывается к нему
 *     и получает снапшот его цены;
 * (б) позиция без оффера (или с нулевым остатком) удаляется и попадает
 *     в removedItems в форме CartItem обычной корзины (+ productId/title) —
 *     источник подсказки «Некоторых товаров нет в наличии» и затемнённых
 *     карточек удалённых позиций;
 * (в) одинаковые позиции сливаются в одну позицию целевого магазина;
 * (г) дочерние позиции (товарные добавки) едут вместе с родителем; нет оффера
 *     у добавки — удаляется вся позиция;
 * (д) промокоды покинутых магазинов снимаются;
 * (е) магазин резолвится как при добавлении: явный storeId, иначе первый
 *     доступный по активному адресу; ни того ни другого — 422.
 * В легаси-режиме (флаг выключен) ручка ничего не меняет.
 */
class NetworkCatalogCartStoreSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Store $anchor;

    private Store $storeA;

    private Store $storeB;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->anchor = Store::factory()->create(['code' => 'switch-anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'switch-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'switch-b', 'is_active' => true]);

        $this->setNetworkCatalog(true);

        $this->product = $this->networkProduct();
        $this->offer($this->storeA, $this->product, price: 640.0, priceOld: 700.0, quantity: 10);

        $this->user = User::factory()->create();
    }

    // ==================== 1. Перепривязка и удаление ====================

    /**
     * Товар есть в целевом магазине — позиция переезжает в него со снапшотом
     * цены его оффера; в ответе группа только целевого магазина.
     */
    public function test_switch_store_rebinds_items_with_offer_and_snapshots_target_price(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $item = $this->addToCart(quantity: 2);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertSame($this->storeB->id, (int) $response->json('storeId'));
        $this->assertSame([], $response->json('removedItems'));
        $this->assertFalse($response->json('hasUnavailableItems'));
        $this->assertCount(1, $response->json('storeGroups'));
        $this->assertSame($this->storeB->id, (int) $response->json('storeGroups.0.store.id'));
        $this->assertEquals(500.0, (float) $response->json('storeGroups.0.items.0.price'));

        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'store_id' => $this->storeB->id,
            'quantity' => 2,
            'price' => 500.0,
            'price_old' => null,
        ]);
        $this->assertDatabaseMissing('cart_items', ['store_id' => $this->storeA->id]);
    }

    /**
     * Товара нет в целевом магазине — позиция удаляется и попадает в removedItems.
     */
    public function test_switch_store_removes_items_without_offer_and_reports_them(): void
    {
        $this->addToCart();

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertTrue($response->json('hasUnavailableItems'));
        $this->assertSame([$this->product->id], array_column($response->json('removedItems'), 'productId'));
        $this->assertSame([$this->product->title], array_column($response->json('removedItems'), 'title'));
        $this->assertSame([], $response->json('storeGroups'));
        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    /**
     * Удалённая позиция отдаётся в форме CartItem обычной корзины (та же
     * CartItemResource, что у GET /cart) — клиент рисует её той же карточкой,
     * только затемнённой. Карточка в контексте магазина, где позиция лежала:
     * ровно то, что клиент видел в корзине до переключения.
     * productId/title — прежний контракт, остаются рядом.
     */
    public function test_removed_item_has_cart_item_shape_as_seen_before_switch(): void
    {
        $item = $this->addToCart(quantity: 2);
        $before = $this->actingAs($this->user)->getJson('/api/v1/cart')->json('storeGroups.0.items.0');

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('removedItems'));
        $removed = $response->json('removedItems.0');

        $this->assertSame($this->product->id, $removed['productId']);
        $this->assertSame($this->product->title, $removed['title']);

        $this->assertSame($item->id, $removed['id']);
        $this->assertSame(2, $removed['quantity']);
        $this->assertEquals(640.0, $removed['price']);
        $this->assertEquals(700.0, $removed['priceOld']);
        $this->assertSame(9, $removed['discountPercent']);
        $this->assertSame($this->product->id, $removed['product']['id']);
        $this->assertSame($this->product->title, $removed['product']['title']);
        $this->assertArrayHasKey('slug', $removed['product']);
        $this->assertArrayHasKey('images', $removed['product']);
        $this->assertArrayHasKey('weight', $removed['product']);
        $this->assertArrayHasKey('measurementUnitLabel', $removed['product']);
        $this->assertEquals(640.0, $removed['product']['price']);
        $this->assertSame(10, $removed['product']['stockQuantity']);

        unset($removed['productId'], $removed['title']);
        $this->assertSame($before, $removed);
    }

    /**
     * Оффер в целевом магазине есть, но остаток нулевой — позиция удаляется,
     * а её карточка остаётся в контексте ИСХОДНОГО магазина (цена и остаток
     * оттуда), а не целевого.
     */
    public function test_removed_item_keeps_source_store_context_when_target_has_zero_stock(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 0);
        $this->addToCart();

        $removed = $this->switchStore($this->storeB->id)->json('removedItems.0');

        $this->assertEquals(640.0, $removed['price']);
        $this->assertEquals(640.0, $removed['product']['price']);
        $this->assertSame(10, $removed['product']['stockQuantity']);
        $this->assertSame(10, $removed['quantityInStock']);
    }

    /**
     * Оффер есть, но остаток нулевой — товара нет в наличии, позиция удаляется.
     */
    public function test_switch_store_removes_items_with_zero_stock_in_target(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 0);
        $this->addToCart();

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertTrue($response->json('hasUnavailableItems'));
        $this->assertCount(1, $response->json('removedItems'));
        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    /**
     * Магазин не учитывает остатки — нулевой остаток оффера не делает товар недоступным.
     */
    public function test_switch_store_keeps_zero_stock_item_when_target_ignores_stock(): void
    {
        $ignoring = Store::factory()->ignoreStock()->create(['code' => 'switch-ignore', 'is_active' => true]);
        $this->offer($ignoring, $this->product, price: 500.0, quantity: 0);
        $item = $this->addToCart();

        $response = $this->switchStore($ignoring->id);

        $response->assertOk();
        $this->assertFalse($response->json('hasUnavailableItems'));
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'store_id' => $ignoring->id, 'price' => 500.0]);
    }

    /**
     * Остатка меньше, чем в позиции, но он есть — позиция остаётся (клиент
     * увидит stockWarning), количество не режется.
     */
    public function test_switch_store_keeps_item_when_target_stock_is_lower_than_quantity(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 2);
        $item = $this->addToCart(quantity: 5);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertFalse($response->json('hasUnavailableItems'));
        $this->assertNotNull($response->json('storeGroups.0.items.0.stockWarning'));
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'store_id' => $this->storeB->id, 'quantity' => 5]);
    }

    /**
     * Позиция уже лежит в целевом магазине — остаётся как есть; а если её
     * оффер там пропал — удаляется и тоже попадает в removedItems.
     */
    public function test_switch_store_revalidates_items_already_in_target_store(): void
    {
        $vanishing = $this->networkProduct();
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $this->offer($this->storeB, $vanishing, price: 90.0, quantity: 4);

        $this->addToCart(storeId: $this->storeB->id);
        $this->addToCart(product: $vanishing, storeId: $this->storeB->id);
        StoreProduct::where('store_id', $this->storeB->id)
            ->where('product_id', $vanishing->id)
            ->update(['is_active' => false]);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertTrue($response->json('hasUnavailableItems'));
        $this->assertSame([$vanishing->id], array_column($response->json('removedItems'), 'productId'));
        $this->assertDatabaseHas('cart_items', ['product_id' => $this->product->id, 'store_id' => $this->storeB->id]);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $vanishing->id]);
    }

    /**
     * Неактивная карточка недоступна в любом магазине.
     */
    public function test_switch_store_removes_inactive_product(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $this->addToCart();
        $this->product->update(['is_active' => false]);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertTrue($response->json('hasUnavailableItems'));
        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    // ==================== 2. Слияние ====================

    /**
     * Тот же товар уже есть в целевом магазине — количества суммируются
     * в одну позицию целевого магазина.
     */
    public function test_switch_store_merges_into_existing_item_of_target_store(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 10);
        $this->addToCart(quantity: 1);
        $this->addToCart(quantity: 2, storeId: $this->storeB->id);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertSame([], $response->json('removedItems'));
        $this->assertSame(1, CartItem::where('product_id', $this->product->id)->count());
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeB->id,
            'quantity' => 3,
            'price' => 500.0,
        ]);
    }

    // ==================== 3. Товарные добавки ====================

    /**
     * Дочерние позиции (товарные добавки) переезжают вместе с родителем
     * и получают цену оффера добавки в целевом магазине.
     */
    public function test_switch_store_rebinds_catalog_supplement_children(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $this->offer($this->storeB, $value->product, price: 100.0, quantity: 7);
        $this->addToCart(supplements: [['supplementId' => $supplement->id, 'valueIds' => [$value->id]]]);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertSame([], $response->json('removedItems'));
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeB->id,
            'price' => 500.0,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $value->product_id,
            'store_id' => $this->storeB->id,
            'price' => 100.0,
        ]);
        $this->assertDatabaseMissing('cart_items', ['store_id' => $this->storeA->id]);
    }

    /**
     * У добавки нет оффера в целевом магазине — недоступна вся позиция:
     * удаляется родитель (дочерние каскадом), в removedItems только товар.
     */
    public function test_switch_store_removes_item_when_supplement_has_no_offer_in_target(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $this->addToCart(supplements: [['supplementId' => $supplement->id, 'valueIds' => [$value->id]]]);

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertTrue($response->json('hasUnavailableItems'));
        $this->assertSame([$this->product->id], array_column($response->json('removedItems'), 'productId'));
        $this->assertSame(0, CartItem::count());
    }

    /**
     * Удалённая позиция с товарными добавками отдаёт их в supplements[]
     * и supplementsTotal — как обычная позиция корзины (снапшот цены
     * добавки в исходном магазине).
     */
    public function test_removed_item_includes_its_catalog_supplements(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $this->addToCart(supplements: [['supplementId' => $supplement->id, 'valueIds' => [$value->id]]]);
        $before = $this->actingAs($this->user)->getJson('/api/v1/cart')->json('storeGroups.0.items.0');

        $removed = $this->switchStore($this->storeB->id)->json('removedItems.0');

        $this->assertSame($supplement->id, $removed['supplements'][0]['supplementId']);
        $this->assertSame($value->id, $removed['supplements'][0]['values'][0]['id']);
        $this->assertSame(120, $removed['supplementsTotal']);

        unset($removed['productId'], $removed['title']);
        $this->assertSame($before, $removed);
    }

    // ==================== 4. Промокоды ====================

    /**
     * Промокод магазина, в котором не осталось позиций, снимается.
     */
    public function test_switch_store_drops_promocode_of_abandoned_store(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $this->addToCart();
        $promocode = Promocode::factory()->percent(10)->create([
            'store_id' => $this->storeA->id,
            'scope' => Promocode::SCOPE_STORE,
            'code' => 'SWITCH10',
        ]);
        $cart = Cart::where('user_id', $this->user->id)->firstOrFail();
        $cart->promocodes()->create(['store_id' => $this->storeA->id, 'promocode_id' => $promocode->id]);

        $this->switchStore($this->storeB->id)->assertOk();

        $this->assertDatabaseMissing('cart_promocodes', ['cart_id' => $cart->id, 'store_id' => $this->storeA->id]);
    }

    // ==================== 5. Резолв магазина ====================

    /**
     * Без storeId магазин берётся первым доступным по активному адресу —
     * ровно как при добавлении в корзину.
     */
    public function test_switch_store_resolves_store_from_active_address(): void
    {
        $this->offer($this->storeB, $this->product, price: 500.0, quantity: 4);
        $item = $this->addToCart();
        $this->addressResolvingTo($this->storeB);

        $response = $this->switchStore(null);

        $response->assertOk();
        $this->assertSame($this->storeB->id, (int) $response->json('storeId'));
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'store_id' => $this->storeB->id]);
    }

    /**
     * Ни storeId, ни активного адреса — 422, корзина не тронута.
     */
    public function test_switch_store_without_store_context_returns_422(): void
    {
        $item = $this->addToCart();

        $response = $this->switchStore(null);

        $response->assertStatus(422)->assertJsonValidationErrors(['storeId']);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'store_id' => $this->storeA->id]);
    }

    /**
     * Неактивный магазин отклоняется валидацией.
     */
    public function test_switch_store_rejects_inactive_store(): void
    {
        $inactive = Store::factory()->create(['code' => 'switch-off', 'is_active' => false]);
        $this->addToCart();

        $this->switchStore($inactive->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['storeId']);
    }

    /**
     * Корзины ещё нет — пустая форма без ошибки.
     */
    public function test_switch_store_without_cart_returns_empty_form(): void
    {
        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertSame([], $response->json('storeGroups'));
        $this->assertSame([], $response->json('removedItems'));
        $this->assertFalse($response->json('hasUnavailableItems'));
    }

    public function test_switch_store_requires_auth(): void
    {
        $this->postJson('/api/v1/cart/store', ['storeId' => $this->storeB->id])->assertStatus(401);
    }

    // ==================== 6. Легаси-режим ====================

    /**
     * Флаг выключен: у товара один магазин-владелец, перепривязка
     * невозможна — ручка ничего не меняет и ничего не удаляет.
     */
    public function test_switch_store_is_noop_in_legacy_mode(): void
    {
        $this->setNetworkCatalog(false);
        $legacyProduct = Product::factory()->create([
            'store_id' => $this->storeA->id,
            'is_active' => true,
            'price' => 100,
            'quantity' => 5,
            'quantity_step' => 1,
        ]);
        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $legacyProduct->id,
            'quantity' => 1,
        ])->assertSuccessful();

        $response = $this->switchStore($this->storeB->id);

        $response->assertOk();
        $this->assertSame([], $response->json('removedItems'));
        $this->assertFalse($response->json('hasUnavailableItems'));
        $this->assertDatabaseHas('cart_items', ['product_id' => $legacyProduct->id, 'store_id' => $this->storeA->id]);
    }

    // ==================== Хелперы ====================

    private function switchStore(?int $storeId): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            '/api/v1/cart/store',
            $storeId === null ? [] : ['storeId' => $storeId],
        );
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

    private function networkProduct(int $cardQuantity = 0): Product
    {
        return Product::factory()->create([
            'store_id' => $this->anchor->id,
            'is_active' => true,
            'price' => 0,
            'quantity' => $cardQuantity,
            'quantity_step' => 1,
        ]);
    }

    private function offer(Store $store, Product $product, float $price, int $quantity, ?float $priceOld = null): StoreProduct
    {
        return StoreProduct::create([
            'store_id' => $store->id,
            'product_id' => $product->id,
            'price' => $price,
            'price_old' => $priceOld,
            'quantity' => $quantity,
        ]);
    }

    private function addressResolvingTo(Store $store): Address
    {
        $address = Address::factory()->active()->create([
            'user_id' => $this->user->id,
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        /** @var FakeSlotsApiClient $slots */
        $slots = $this->app->make(SlotsApiClientInterface::class);
        $slots->setShops([
            ['id' => 1, 'code' => $store->code, 'delivery_price' => 200, 'min_order_price' => 500],
        ]);

        return $address;
    }

    /**
     * Товарная добавка к $this->product с оффером в магазине A.
     *
     * @return array{0: Supplement, 1: SupplementValue}
     */
    private function catalogSupplement(float $price, int $offerQuantity): array
    {
        $supplementProduct = $this->networkProduct();
        $this->offer($this->storeA, $supplementProduct, price: $price, quantity: $offerQuantity);

        $supplement = Supplement::create([
            'product_id' => $this->product->id,
            'title' => 'Соус',
            'type' => 'many',
        ]);

        $value = SupplementValue::create([
            'supplement_id' => $supplement->id,
            'title' => 'Сырный соус',
            'price' => null,
            'product_id' => $supplementProduct->id,
            'position' => 1,
        ]);

        return [$supplement, $value->load('product')];
    }

    /**
     * Добавить товар сети в корзину (по умолчанию — в магазин A).
     */
    private function addToCart(
        int $quantity = 1,
        ?Product $product = null,
        ?int $storeId = null,
        ?array $supplements = null,
    ): CartItem {
        $product ??= $this->product;
        $storeId ??= $this->storeA->id;

        $payload = [
            'productId' => $product->id,
            'quantity' => $quantity,
            'storeId' => $storeId,
        ];
        if ($supplements !== null) {
            $payload['supplements'] = $supplements;
        }

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', $payload)->assertSuccessful();

        $cart = Cart::where('user_id', $this->user->id)->firstOrFail();

        return $cart->items()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->whereNull('parent_id')
            ->firstOrFail();
    }
}
