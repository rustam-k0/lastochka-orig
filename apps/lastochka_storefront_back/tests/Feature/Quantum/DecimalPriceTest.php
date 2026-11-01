<?php

declare(strict_types=1);

namespace Tests\Feature\Quantum;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Цены с копейками по всей цепочке storefront (LASTOCHKI-JAMI-11200,
 * решение «округление до целого рубля недопустимо — чеки/эквайринг»).
 */
class DecimalPriceTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Store
    {
        return Store::factory()->create();
    }

    private function makeQuantumProduct(Store $store, float $price = 135.80): Product
    {
        return Product::factory()->create([
            'store_id' => $store->id,
            'price' => $price,
            'quantity' => 10000,
            'quantity_step' => 200,
            'measurement_unit' => 'gram',
            'is_active' => true,
        ]);
    }

    public function test_product_stores_kopeck_price(): void
    {
        $product = $this->makeQuantumProduct($this->makeStore());

        $product->refresh();

        $this->assertSame(135.80, (float) $product->price);
        $this->assertIsFloat($product->price);
    }

    public function test_price_updated_event_keeps_kopecks_and_applies_quantum_step(): void
    {
        $store = $this->makeStore();
        $product = Product::factory()->create([
            'store_id' => $store->id,
            'price' => 680,
            'quantity_step' => 1,
            'measurement_unit' => 'kilogram',
        ]);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-1',
            'price' => 136.33,
            'raw_price' => 681.65,
            'quantity_step' => 200,
            'measurement_unit' => 'gram',
            'unit_type' => 'g',
        ]);

        $product->refresh();

        $this->assertSame(136.33, (float) $product->price);
        $this->assertSame(200, (int) $product->quantity_step);
        $this->assertSame('gram', $product->measurement_unit->value);
    }

    public function test_price_updated_without_step_preserves_existing_step(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-2',
            'price' => 140.50,
        ]);

        $product->refresh();

        $this->assertSame(140.50, (float) $product->price);
        $this->assertSame(200, (int) $product->quantity_step);
        $this->assertSame('gram', $product->measurement_unit->value);
    }

    public function test_from_event_does_not_truncate_kopecks(): void
    {
        $store = $this->makeStore();

        $attributes = app(ProductEventTransformer::class)->fromEvent([
            'id' => 'guid-1',
            'shop_code' => $store->code,
            'source' => '1c',
            'sku' => '173845',
            'title' => 'Жюльен',
            'price' => 135.80,
            'quantity' => 2500,
            'unit_type' => 'g',
            'measurement_unit' => 'gram',
            'quantity_step' => 200,
        ]);

        $this->assertSame(135.80, $attributes['price']);
        $this->assertSame(200, $attributes['quantity_step']);
    }

    public function test_cart_item_total_counts_kopecks_per_portion(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store, 135.80);

        $cart = Cart::factory()->create();
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'price' => $product->getEffectivePrice(),
            'quantity' => 600, // 3 кванта по 200 г
        ]);

        $item->refresh();

        $this->assertSame(3, $item->portions);
        $this->assertSame(135.80, (float) $item->price);
        // 135.80 × 3 = 407.40 — без потери копеек
        $this->assertSame(407.40, round($item->price * $item->portions, 2));
    }

    public function test_effective_price_returns_float_kopecks(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store, 135.80);

        $this->assertSame(135.80, round((float) $product->getEffectivePrice(), 2));
    }

    public function test_order_item_resource_keeps_kopecks(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store, 135.80);

        $order = \App\Models\Order::factory()->create([
            'store_id' => $store->id,
            'subtotal' => 407.40,
            'discount' => 10.55,
            'delivery_cost' => 99.00,
            'total' => 495.85,
        ]);

        $item = \App\Models\OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title' => $product->title,
            'sku' => $product->sku ?? '',
            'price' => 135.80,
            'quantity' => 600,
            'total' => 407.40,
            'measurement_unit' => 'gram',
        ]);

        $itemResource = (new \App\Http\Resources\Api\V1\OrderItemResource($item->fresh()))->toArray(request());
        $this->assertSame(135.8, $itemResource['price']);
        $this->assertSame(407.4, $itemResource['total']);

        $orderResource = (new \App\Http\Resources\Api\V1\OrderResource($order->fresh()))->toArray(request());
        $this->assertSame(407.4, $orderResource['subtotal']);
        $this->assertSame(10.55, $orderResource['discount']);
        $this->assertSame(99.0, $orderResource['deliveryCost']);
        $this->assertSame(495.85, $orderResource['total']);
    }

    public function test_quantity_step_change_clears_cart_items(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store, 136.00);

        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'price' => 136.00,
            'quantity' => 600,
        ]);

        // Смена кванта 200 → 300: позиции корзины с количеством в старых
        // единицах шага становятся невалидными и удаляются.
        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-step-change',
            'price' => 204.00,
            'quantity_step' => 300,
            'measurement_unit' => 'gram',
            'unit_type' => 'g',
        ]);

        $this->assertSame(0, CartItem::where('product_id', $product->id)->count());

        $product->refresh();
        $this->assertSame(300, (int) $product->quantity_step);
    }

    public function test_same_quantity_step_keeps_cart_items(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store, 136.00);

        $cart = Cart::factory()->create();
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'price' => 136.00,
            'quantity' => 600,
        ]);

        // Обычное обновление цены без смены шага — корзина не трогается.
        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-price-only',
            'price' => 140.50,
            'quantity_step' => 200,
            'measurement_unit' => 'gram',
            'unit_type' => 'g',
        ]);

        $this->assertSame(1, CartItem::where('product_id', $product->id)->count());
    }

    public function test_product_resource_returns_decimal_price_as_number(): void
    {
        $store = $this->makeStore();
        $product = $this->makeQuantumProduct($store, 135.80);

        $resource = (new \App\Http\Resources\Api\V1\ProductResource($product->fresh()))
            ->toArray(request());

        $this->assertSame(135.8, (float) $resource['price']);
        $this->assertIsFloat($resource['price']);
    }
}
