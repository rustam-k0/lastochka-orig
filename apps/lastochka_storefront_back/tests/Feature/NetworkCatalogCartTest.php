<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Order\ReleaseProductsAction;
use App\Actions\Order\ReserveProductsAction;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Корзина и резервирование в сетевом каталоге (network_catalog).
 *
 * Контракт:
 * (а) cart_items.store_id — магазин ПОКУПКИ (явный storeId запроса либо первый
 *     доступный магазин активного адреса), а не якорный магазин карточки;
 * (б) cart_items.price/price_old — снапшот цены оффера этого магазина;
 * (в) остаток проверяется по офферу, а не по колонке products.quantity;
 * (г) товар без валидного оффера в выбранном магазине в корзину не попадает;
 * (д) выдача и пересчёт корзины (GET /cart, PUT /cart/items) не затирают
 *     снапшот нулями карточки и не вычищают позиции.
 * При выключенном флаге поведение прежнее (легаси байт-в-байт).
 */
class NetworkCatalogCartTest extends TestCase
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

        $this->anchor = Store::factory()->create(['code' => 'cart-anchor', 'is_active' => true]);
        $this->storeA = Store::factory()->create(['code' => 'cart-a', 'is_active' => true]);
        $this->storeB = Store::factory()->create(['code' => 'cart-b', 'is_active' => true]);

        $this->setNetworkCatalog(true);

        $this->product = $this->networkProduct();
        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $this->product->id,
            'price' => 640.0,
            'price_old' => 700.0,
            'quantity' => 10,
        ]);

        $this->user = User::factory()->create();
    }

    /**
     * Флаг модуля хранится в storefront_settings.project_modules (type=json).
     * ModuleConfigService — singleton с мемоизацией, поэтому после записи
     * сбрасываем и кеш, и мемо.
     */
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

    /**
     * Глобальная карточка сети: владелец — якорный магазин, цена/остаток
     * карточки нулевые (живут в store_products).
     */
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

    /**
     * Активный адрес пользователя, который slots-api резолвит в $store.
     */
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

    // ==================== 1. Добавление в корзину ====================

    public function test_add_to_cart_with_store_id_snapshots_store_price(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'storeId' => $this->storeA->id,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeA->id,
            'price' => 640.0,
            'price_old' => 700.0,
        ]);
    }

    public function test_add_to_cart_rejects_store_without_offer(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'storeId' => $this->storeB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    /**
     * Оффер с price=0 (создан stock-событием раньше цены) оффером не считается.
     */
    public function test_add_to_cart_rejects_offer_without_price(): void
    {
        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $this->product->id,
            'price' => 0,
            'quantity' => 5,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'storeId' => $this->storeB->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    public function test_add_to_cart_resolves_store_from_active_address(): void
    {
        $this->addressResolvingTo($this->storeA);

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeA->id,
            'price' => 640.0,
        ]);
    }

    public function test_add_to_cart_without_store_context_returns_422(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('storeId');
        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    /**
     * Остаток берётся из оффера: щедрая колонка карточки не должна пускать
     * в корзину количество, которого нет в магазине покупки.
     */
    public function test_add_to_cart_checks_offer_stock_not_card_stock(): void
    {
        $product = $this->networkProduct(cardQuantity: 100);
        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'price' => 100.0,
            'quantity' => 2,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $product->id,
            'quantity' => 3,
            'storeId' => $this->storeA->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
    }

    public function test_batch_add_to_cart_uses_store_offer(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/cart/items/batch', [
            'storeId' => $this->storeA->id,
            'items' => [
                ['productId' => $this->product->id, 'quantity' => 2],
            ],
        ])->assertOk()->assertJson(['added' => 1, 'skipped' => 0]);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeA->id,
            'price' => 640.0,
        ]);
    }

    /**
     * Один товар, купленный в двух магазинах, — две позиции со своими
     * снапшотами: слияние затёрло бы цену чужого магазина.
     */
    public function test_same_product_in_two_stores_creates_separate_items(): void
    {
        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $this->product->id,
            'price' => 500.0,
            'quantity' => 4,
        ]);

        $this->addToCart();
        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 2,
            'storeId' => $this->storeB->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeA->id,
            'quantity' => 1,
            'price' => 640.0,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeB->id,
            'quantity' => 2,
            'price' => 500.0,
        ]);
        $this->assertSame(2, CartItem::where('product_id', $this->product->id)->count());
    }

    /**
     * Повторное добавление в тот же магазин по-прежнему суммирует количество.
     */
    public function test_repeated_add_in_same_store_merges_quantity(): void
    {
        $this->addToCart();
        $this->addToCart(quantity: 2);

        $this->assertSame(1, CartItem::where('product_id', $this->product->id)->count());
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'store_id' => $this->storeA->id,
            'quantity' => 3,
            'price' => 640.0,
        ]);
    }

    // ==================== 2. Выдача и пересчёт корзины ====================

    /**
     * GET /cart пересчитывает цены позиций — в сетевом режиме источник
     * пересчёта тот же оффер, а не нулевые колонки карточки.
     */
    public function test_cart_index_keeps_store_snapshot(): void
    {
        $this->addToCart();

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart');
        $response->assertOk();

        $item = $response->json('storeGroups.0.items.0');
        $this->assertNotNull($item, 'позиция не должна вычищаться из сетевой корзины');
        $this->assertEquals(640.0, (float) $item['price']);
        $this->assertEquals(700.0, (float) $item['priceOld']);
        $this->assertSame(10, (int) $item['quantityInStock']);
        $this->assertEquals(640.0, (float) $item['product']['price']);
        $this->assertSame(10, (int) $item['product']['stockQuantity']);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'price' => 640.0,
        ]);
    }

    /**
     * Смена цены оффера подхватывается пересчётом корзины.
     */
    public function test_cart_index_syncs_price_from_offer(): void
    {
        $this->addToCart();

        StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $this->product->id)
            ->update(['price' => 590.0, 'price_old' => null]);

        $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->product->id,
            'price' => 590.0,
            'price_old' => null,
        ]);
    }

    /**
     * Оффер магазина покупки исчез — позиция недоступна и удаляется.
     */
    public function test_cart_index_removes_item_without_offer(): void
    {
        $this->addToCart();

        StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $this->product->id)
            ->update(['is_active' => false]);

        $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    /**
     * Карточка сети всегда с нулевым остатком — это не повод чистить корзину.
     */
    public function test_cart_index_keeps_item_with_zero_card_stock(): void
    {
        $this->addToCart();

        $this->assertSame(0, (int) $this->product->fresh()->quantity);

        $this->actingAs($this->user)->getJson('/api/v1/cart')->assertOk();

        $this->assertDatabaseHas('cart_items', ['product_id' => $this->product->id]);
    }

    public function test_update_cart_item_uses_store_offer(): void
    {
        $item = $this->addToCart();

        $this->actingAs($this->user)->putJson("/api/v1/cart/items/{$item->id}", [
            'quantity' => 3,
        ])->assertOk();

        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'quantity' => 3,
            'price' => 640.0,
            'price_old' => 700.0,
        ]);
    }

    public function test_update_cart_item_checks_offer_stock(): void
    {
        $item = $this->addToCart();

        $this->actingAs($this->user)->putJson("/api/v1/cart/items/{$item->id}", [
            'quantity' => 11,
        ])->assertStatus(422);

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }

    // ==================== 3. Резервирование при заказе ====================

    /**
     * Резервирование сверяет остаток с оффером магазина позиции: карточка
     * сети всегда нулевая, и легаси-проверка блокировала бы любой заказ.
     * При stock_mode=normal (по умолчанию) остаток ведёт 1С — оффер не трогаем.
     */
    public function test_reserve_products_uses_store_offer_stock(): void
    {
        $item = $this->addToCart(quantity: 4);

        app(ReserveProductsAction::class)->execute(collect([$item->fresh()]));

        $this->assertSame('normal', (string) $this->product->fresh()->stock_mode);
        $this->assertSame(10, $this->offerQuantity($this->storeA), 'normal не списывает остаток оффера');
        $this->assertSame(0, (int) $this->product->fresh()->quantity, 'карточка сети остаётся нулевой');
    }

    public function test_reserve_products_rejects_quantity_above_offer(): void
    {
        $item = $this->addToCart(quantity: 4);

        StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 1]);

        $this->expectException(\App\Exceptions\InsufficientStockException::class);

        app(ReserveProductsAction::class)->execute(collect([$item->fresh()]));
    }

    /**
     * stock_mode=fixed — остаток ведётся вручную, декремент идёт в оффер
     * магазина покупки, а не в нулевую колонку карточки.
     */
    public function test_reserve_products_decrements_offer_for_fixed_stock_mode(): void
    {
        $this->product->update(['stock_mode' => 'fixed']);
        $item = $this->addToCart(quantity: 4);

        app(ReserveProductsAction::class)->execute(collect([$item->fresh()]));

        $this->assertSame(6, $this->offerQuantity($this->storeA));
        $this->assertSame(0, (int) $this->product->fresh()->quantity);
    }

    /**
     * Резерв и возврат ходят по одному предикату: при ignore_stock магазина
     * плюс stock_mode=fixed остаток обязан вернуться, иначе он утекает
     * на каждой отмене (10 → 6 → 10).
     */
    public function test_release_returns_offer_stock_symmetrically_with_reserve(): void
    {
        $this->storeA->update(['ignore_stock' => true]);
        $this->product->update(['stock_mode' => 'fixed']);

        $item = $this->addToCart(quantity: 4);

        app(ReserveProductsAction::class)->execute(collect([$item->fresh()]));
        $this->assertSame(6, $this->offerQuantity($this->storeA), 'резерв списал остаток оффера');

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
        ]);

        app(ReleaseProductsAction::class)->execute($order->fresh('items'));

        $this->assertSame(10, $this->offerQuantity($this->storeA), 'возврат обязан вернуть тот же остаток');
        $this->assertSame(0, (int) $this->product->fresh()->quantity, 'карточка сети не трогается');
    }

    /**
     * stock_mode=normal: остаток ведёт 1С — возврат не раздувает синкнутый оффер.
     */
    public function test_release_does_not_inflate_offer_for_synced_stock(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
        ]);

        app(ReleaseProductsAction::class)->execute($order->fresh('items'));

        $this->assertSame(10, $this->offerQuantity($this->storeA));
        $this->assertSame(0, (int) $this->product->fresh()->quantity);
    }

    // ==================== 4. Товарные добавки ====================

    /**
     * Каталожная добавка с валидным оффером не должна блокировать добавление:
     * её остаток тоже живёт в store_products, а не в нулевой карточке.
     */
    public function test_add_to_cart_with_catalog_supplement_uses_offer_stock(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'storeId' => $this->storeA->id,
            'supplements' => [
                ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
            ],
        ]);

        $response->assertSuccessful();

        // Дочерняя позиция создана в магазине покупки с ценой оффера добавки.
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $value->product_id,
            'store_id' => $this->storeA->id,
            'price' => 120.0,
        ]);
    }

    /**
     * Добавки не хватает по офферу магазина покупки — 422, даже если
     * у глобальной карточки остаток «щедрый».
     */
    public function test_add_to_cart_rejects_catalog_supplement_above_offer_stock(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 1, cardQuantity: 100);

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 3,
            'storeId' => $this->storeA->id,
            'supplements' => [
                ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
    }

    /**
     * У добавки нет оффера в магазине покупки — 422, а не дочерняя позиция
     * с нулевой ценой, которая уехала бы в заказ.
     */
    public function test_add_to_cart_rejects_catalog_supplement_without_offer(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7, withOffer: false);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'storeId' => $this->storeA->id,
            'supplements' => [
                ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'недоступна в выбранном магазине',
            (string) $response->json('message'),
            'причина — отсутствие оффера, а не мнимая нехватка остатка карточки'
        );

        $this->assertDatabaseMissing('cart_items', ['product_id' => $this->product->id]);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $value->product_id]);
    }

    /**
     * Страховка на уровне Action (в обход FormRequest): дочерняя позиция без
     * оффера не должна создаваться с нулевой ценой — она уехала бы в заказ.
     */
    public function test_add_to_cart_action_rejects_supplement_without_offer(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7, withOffer: false);

        try {
            app(AddToCartAction::class)->execute(
                $this->user,
                $this->product->id,
                1,
                [['supplementId' => $supplement->id, 'valueIds' => [$value->id]]],
                null,
                $this->storeA->id,
            );
            $this->fail('ожидалась ValidationException для добавки без оффера');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        $this->assertDatabaseMissing('cart_items', ['product_id' => $value->product_id]);
    }

    /**
     * ВЫБРАННАЯ добавка в корзине рендерится из childItems.sourceSupplementValue:
     * цена — снапшот дочерней позиции (та же, что в supplementsTotal),
     * остаток — из оффера магазина покупки, а не нули глобальной карточки.
     */
    public function test_cart_selected_supplement_uses_snapshot_and_offer(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'storeId' => $this->storeA->id,
            'supplements' => [
                ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
            ],
        ])->assertSuccessful();

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart');
        $response->assertOk();

        $item = $response->json('storeGroups.0.items.0');
        $this->assertSame(120, (int) $item['supplementsTotal']);

        $selected = collect($item['supplements'])->firstWhere('supplementId', $supplement->id);
        $this->assertNotNull($selected, 'выбранная добавка должна быть в выдаче позиции');
        $this->assertSame(120, (int) $selected['values'][0]['price'], 'цена добавки — снапшот, а не 0 карточки');
        $this->assertSame(7, (int) $selected['values'][0]['stockQuantity'], 'остаток — из оффера магазина покупки');
    }

    /**
     * Изоляция магазинов: одна и та же карточка добавки приходит из eager load
     * одним инстансом на всю корзину — позиция магазина B не должна перетирать
     * офферы позиции магазина A.
     */
    public function test_cart_supplement_offers_are_isolated_between_stores(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);

        // Тот же товар-добавка продаётся в магазине B дороже и в другом количестве.
        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $value->product_id,
            'price' => 200.0,
            'quantity' => 2,
        ]);
        // И сам товар доступен в магазине B.
        StoreProduct::create([
            'store_id' => $this->storeB->id,
            'product_id' => $this->product->id,
            'price' => 500.0,
            'quantity' => 5,
        ]);

        foreach ([$this->storeA, $this->storeB] as $store) {
            $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
                'productId' => $this->product->id,
                'quantity' => 1,
                'storeId' => $store->id,
                'supplements' => [
                    ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
                ],
            ])->assertSuccessful();
        }

        $response = $this->actingAs($this->user)->getJson('/api/v1/cart');
        $response->assertOk();

        $groups = collect($response->json('storeGroups'))->keyBy('store.id');
        $this->assertCount(2, $groups);

        $supplementOf = function (array $group) use ($supplement): array {
            $item = $group['items'][0];

            return collect($item['supplements'])->firstWhere('supplementId', $supplement->id)['values'][0];
        };

        $valueA = $supplementOf($groups[$this->storeA->id]);
        $valueB = $supplementOf($groups[$this->storeB->id]);

        $this->assertSame(120, (int) $valueA['price'], 'позиция магазина A сохранила свою цену добавки');
        $this->assertSame(7, (int) $valueA['stockQuantity'], 'позиция магазина A сохранила свой остаток добавки');
        $this->assertSame(200, (int) $valueB['price']);
        $this->assertSame(2, (int) $valueB['stockQuantity']);
    }

    /**
     * История заказа: цена добавки — снапшот дочерней позиции заказа,
     * а не живой пересчёт по глобальной карточке (в сети он давал 0).
     */
    public function test_order_history_supplement_uses_snapshot_price(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
        ]);
        $parentItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 640.0,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $value->product_id,
            'parent_id' => $parentItem->id,
            'source_supplement_value_id' => $value->id,
            'quantity' => 1,
            'price' => 120.0,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/orders/{$order->id}");
        $response->assertOk();

        $selected = collect($response->json('data.items.0.supplements'))
            ->firstWhere('supplementId', $supplement->id);

        $this->assertNotNull($selected);
        $this->assertSame(120, (int) $selected['values'][0]['price'], 'цена добавки в заказе — снапшот');
        $this->assertNull(
            $selected['values'][0]['stockQuantity'],
            'история заказа не претендует на живой остаток: null вместо нуля карточки'
        );
    }

    /**
     * Добавка со снятым учётом остатка (track_stock=false) не резервируется —
     * значит и возврат не должен её «возвращать», иначе остаток раздувается
     * на каждой отмене.
     */
    public function test_release_skips_untracked_supplement_symmetrically(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 10);
        $value->update(['track_stock' => false]);
        $value->product->update(['stock_mode' => 'fixed']);

        $supplementStore = $this->offerQuantity($this->storeA, $value->product);
        $this->assertSame(10, $supplementStore);

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'store_id' => $this->storeA->id,
        ]);
        $parentItem = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 640.0,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $value->product_id,
            'parent_id' => $parentItem->id,
            'source_supplement_value_id' => $value->id,
            'quantity' => 2,
            'price' => 120.0,
        ]);

        app(ReleaseProductsAction::class)->execute($order->fresh('items'));

        $this->assertSame(
            10,
            $this->offerQuantity($this->storeA, $value->product),
            'остаток добавки без учёта не должен раздуваться возвратом'
        );
    }

    /**
     * Магазин покупки не определился: причина отказа — магазин, а не мнимая
     * недоступность добавки (иначе клиент получает бесполезное сообщение).
     */
    public function test_supplement_validation_defers_to_store_error(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);

        $response = $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => 1,
            'supplements' => [
                ['supplementId' => $supplement->id, 'valueIds' => [$value->id]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('storeId');
    }

    /**
     * Карточка товара отдаёт цену и остаток добавки из оффера магазина
     * контекста, а не нули глобальной карточки (иначе добавка ещё и
     * вырезается из выдачи как «нет на складе»).
     */
    public function test_product_card_supplement_uses_store_offer(): void
    {
        [$supplement, $value] = $this->catalogSupplement(price: 120.0, offerQuantity: 7);

        $response = $this->getJson("/api/v1/products/{$this->product->id}?storeId={$this->storeA->id}");
        $response->assertOk();

        $values = collect($response->json('data.supplements'))
            ->firstWhere('id', $supplement->id)['values'] ?? [];

        $this->assertCount(1, $values, 'добавка с живым оффером не должна вырезаться из карточки');
        $this->assertSame(120, (int) $values[0]['price']);
        $this->assertSame(7, (int) $values[0]['stockQuantity']);
    }

    // ==================== 5. Легаси-режим ====================

    public function test_legacy_mode_snapshots_card_price_and_store(): void
    {
        $this->setNetworkCatalog(false);

        $legacyProduct = Product::factory()->create([
            'store_id' => $this->storeB->id,
            'is_active' => true,
            'price' => 250.0,
            'quantity' => 5,
            'quantity_step' => 1,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $legacyProduct->id,
            'quantity' => 1,
            // storeId в легаси-режиме игнорируется: магазин берётся у карточки.
            'storeId' => $this->storeA->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $legacyProduct->id,
            'store_id' => $this->storeB->id,
            'price' => 250.0,
        ]);
    }

    /**
     * Остаток оффера товара в магазине.
     */
    private function offerQuantity(Store $store, ?Product $product = null): int
    {
        return (int) StoreProduct::where('store_id', $store->id)
            ->where('product_id', ($product ?? $this->product)->id)
            ->value('quantity');
    }

    /**
     * Каталожная (товарная) добавка к основному товару: карточка добавки
     * глобальная и нулевая, остаток и цена — в оффере $offerStore.
     *
     * @return array{0: Supplement, 1: SupplementValue}
     */
    private function catalogSupplement(
        float $price,
        int $offerQuantity,
        int $cardQuantity = 0,
        ?Store $offerStore = null,
        bool $withOffer = true,
    ): array {
        $supplementProduct = $this->networkProduct(cardQuantity: $cardQuantity);

        if ($withOffer) {
            StoreProduct::create([
                'store_id' => ($offerStore ?? $this->storeA)->id,
                'product_id' => $supplementProduct->id,
                'price' => $price,
                'quantity' => $offerQuantity,
            ]);
        }

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

        return [$supplement, $value];
    }

    /**
     * Добавить товар сети в корзину магазина A.
     */
    private function addToCart(int $quantity = 1): CartItem
    {
        $this->actingAs($this->user)->postJson('/api/v1/cart/items', [
            'productId' => $this->product->id,
            'quantity' => $quantity,
            'storeId' => $this->storeA->id,
        ])->assertSuccessful();

        $cart = Cart::where('user_id', $this->user->id)->firstOrFail();

        return $cart->items()->where('product_id', $this->product->id)->firstOrFail();
    }
}
