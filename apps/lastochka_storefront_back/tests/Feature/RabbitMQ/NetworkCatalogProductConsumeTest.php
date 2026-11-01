<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\MeasurementUnit;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Models\StoreProduct;
use App\RabbitMQ\Support\NetworkCatalog;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use App\Services\Settings\ModuleConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Сетевой каталог (network_catalog) на стороне консьюмера storefront:
 * карточка товара глобальна (владелец — якорный store), а цены/остатки
 * приезжают пер-магазинными событиями и ложатся в store_products.
 */
class NetworkCatalogProductConsumeTest extends TestCase
{
    use RefreshDatabase;

    private Store $anchorStore;

    private Store $storeA;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->anchorStore = Store::factory()->create(['code' => 'anchor']);
        $this->storeA = Store::factory()->create(['code' => 'shop-a']);

        $this->setNetworkCatalog(true);
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'created',
            'event_source' => '1c',
            'source' => '1c',
            'id' => 'guid-sf-'.uniqid(),
            'sku' => 'SF-'.mb_strtoupper(uniqid()),
            'title' => 'Вино Разгуляйка красное',
            'unit_type' => 'piece',
        ], $overrides);
    }

    public function test_card_without_shop_code_is_valid_and_uses_anchor_store(): void
    {
        $t = app(ProductEventTransformer::class);
        $this->assertTrue($t->validate($this->cardPayload()));

        $attributes = $t->fromEvent($this->cardPayload());
        $this->assertSame($this->anchorStore->id, $attributes['store_id']);
    }

    public function test_card_with_shop_code_still_uses_anchor_store_in_network_mode(): void
    {
        $t = app(ProductEventTransformer::class);
        $attributes = $t->fromEvent($this->cardPayload(['shop_code' => 'shop-a', 'price' => 300.0]));
        $this->assertSame($this->anchorStore->id, $attributes['store_id']);
    }

    public function test_price_updated_writes_store_products_not_product(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'price' => 0,
            'quantity' => 0,
        ]);

        $t = app(ProductEventTransformer::class);
        $t->processPriceUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'price' => 500.0,
            'discount_price' => 450.0,
        ]);

        $row = StoreProduct::where('store_id', $this->storeA->id)->where('product_id', $product->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals(450.0, (float) $row->price, 'инверсия: клиент платит discount_price');
        $this->assertEquals(500.0, (float) $row->price_old);
        $this->assertEquals(0.0, (float) $product->fresh()->price, 'глобальную колонку не трогаем');
    }

    public function test_price_updated_without_discount_clears_price_old(): void
    {
        $product = Product::factory()->create(['store_id' => $this->anchorStore->id]);

        StoreProduct::create([
            'store_id' => $this->storeA->id,
            'product_id' => $product->id,
            'price' => 450,
            'price_old' => 500,
        ]);

        $t = app(ProductEventTransformer::class);
        $t->processPriceUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'price' => 500.0,
        ]);

        $row = StoreProduct::where('store_id', $this->storeA->id)->where('product_id', $product->id)->first();
        $this->assertEquals(500.0, (float) $row->price);
        $this->assertNull($row->price_old, 'акция закончилась — перечёркнутая цена снимается');
    }

    public function test_price_updated_applies_quantum_card_fields_in_network_mode(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'price' => 0,
            'unit_type' => 'kg',
            'quantity_step' => 100,
            'measurement_unit' => 'piece',
        ]);

        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
        ]);

        $t = app(ProductEventTransformer::class);
        $t->processPriceUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'price' => 120.5,
            'quantity_step' => 250,
            'measurement_unit' => 'gram',
            'unit_type' => 'kg',
        ]);

        $fresh = $product->fresh();
        $this->assertSame(250, (int) $fresh->quantity_step, 'шаг продажи — поле карточки, применяется и в сетевом режиме');
        $this->assertSame(MeasurementUnit::Gram->value, $fresh->measurement_unit->value);
        $this->assertEquals(0.0, (float) $fresh->price, 'цена магазина в products не попадает');

        $this->assertSame(0, CartItem::where('product_id', $product->id)->count(),
            'смена шага чистит позиции корзин, как в легаси');

        $this->assertEquals(120.5, (float) StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $product->id)->value('price'));
    }

    public function test_apply_global_scope_config_follows_flag(): void
    {
        NetworkCatalog::applyGlobalScopeConfig();
        $this->assertTrue(config('rabbitmq-events.entities.products.global_scope'));

        $this->setNetworkCatalog(false);
        NetworkCatalog::applyGlobalScopeConfig();
        $this->assertFalse(config('rabbitmq-events.entities.products.global_scope'),
            'выключение модуля снимает global_scope (иначе config:cache запекал бы true навсегда)');
    }

    public function test_stock_updated_writes_store_products(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'quantity' => 0,
            'unit_type' => 'piece',
        ]);

        $t = app(ProductEventTransformer::class);
        $t->processStockUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'quantity' => 12,
        ]);

        $this->assertSame(12, (int) StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, (int) $product->fresh()->quantity);
    }

    public function test_stock_changed_writes_store_products(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'quantity' => 0,
            'unit_type' => 'piece',
        ]);

        $t = app(ProductEventTransformer::class);
        $t->processStockChangedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'quantity' => 7,
        ]);

        $this->assertSame(7, (int) StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(0, (int) $product->fresh()->quantity);
    }

    public function test_price_updated_without_shop_code_is_dropped(): void
    {
        $product = Product::factory()->create(['store_id' => $this->anchorStore->id]);

        $t = app(ProductEventTransformer::class);
        $t->processPriceUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'price' => 500.0,
        ]);

        $this->assertSame(0, StoreProduct::count());
    }

    public function test_stock_updated_with_unknown_shop_code_is_dropped(): void
    {
        $product = Product::factory()->create(['store_id' => $this->anchorStore->id]);

        $t = app(ProductEventTransformer::class);
        $t->processStockUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-unknown',
            'quantity' => 5,
        ]);

        $this->assertSame(0, StoreProduct::count());
    }

    public function test_stock_ignored_for_fixed_and_ignore_stock_mode(): void
    {
        $t = app(ProductEventTransformer::class);

        foreach (['ignore', 'fixed'] as $mode) {
            $product = Product::factory()->create([
                'store_id' => $this->anchorStore->id,
                'stock_mode' => $mode,
            ]);

            $t->processStockUpdatedEvent($product, [
                'event_id' => (string) Str::uuid(),
                'shop_code' => 'shop-a',
                'quantity' => 42,
            ]);
        }

        $this->assertSame(0, StoreProduct::count(), 'остатком ignore/fixed управляет сайт, а не 1С');
    }

    public function test_weight_product_stock_converts_kg_to_grams(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->anchorStore->id,
            'unit_type' => 'kg',
        ]);

        $t = app(ProductEventTransformer::class);
        $t->processStockUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'quantity' => 3,
        ]);

        $this->assertSame(3000, (int) StoreProduct::where('store_id', $this->storeA->id)
            ->where('product_id', $product->id)->value('quantity'));
    }

    public function test_legacy_mode_unchanged(): void
    {
        $this->setNetworkCatalog(false);

        $product = Product::factory()->create(['store_id' => $this->storeA->id, 'price' => 10, 'quantity' => 1]);
        $t = app(ProductEventTransformer::class);
        $t->processPriceUpdatedEvent($product, [
            'event_id' => (string) Str::uuid(),
            'shop_code' => 'shop-a',
            'price' => 99.0,
        ]);

        $this->assertEquals(99.0, (float) $product->fresh()->price, 'легаси: пишем в колонку products');
        $this->assertSame(0, StoreProduct::count());
    }

    public function test_legacy_mode_card_requires_shop_code_and_price(): void
    {
        $this->setNetworkCatalog(false);

        $t = app(ProductEventTransformer::class);

        $this->assertFalse($t->validate($this->cardPayload()), 'легаси: без shop_code и price карточка невалидна');
        $this->assertTrue($t->validate($this->cardPayload(['shop_code' => 'shop-a', 'price' => 100.0])));

        $attributes = $t->fromEvent($this->cardPayload(['shop_code' => 'shop-a', 'price' => 100.0]));
        $this->assertSame($this->storeA->id, $attributes['store_id'], 'легаси: store из shop_code');
    }
}
