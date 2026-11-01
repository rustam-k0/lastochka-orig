<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Акционная цена, приходящая узким кастомным событием product.price_updated
 * (не полным product.created/updated) — 1c_parser шлёт её при изменении
 * реквизита <ЦенаЗаЕдиницуСоСкидкой>. Та же инверсия цен, что уже покрыта
 * для полного события в ProductEventHandlerTest — здесь для processPriceUpdatedEvent.
 */
final class ProductPriceUpdatedDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(Store $store, float $price = 100.00, ?float $priceOld = null): Product
    {
        return Product::factory()->create([
            'store_id' => $store->id,
            'price' => $price,
            'price_old' => $priceOld,
        ]);
    }

    public function test_price_updated_event_with_discount_price_inverts_prices(): void
    {
        $store = Store::factory()->create();
        $product = $this->makeProduct($store, 100.00);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-discount-1',
            'price' => 100.00,
            'discount_price' => 79.00,
        ]);

        $product->refresh();

        $this->assertSame(79.00, (float) $product->price);
        $this->assertSame(100.00, (float) $product->price_old);
    }

    public function test_price_updated_event_without_discount_price_has_no_price_old(): void
    {
        $store = Store::factory()->create();
        $product = $this->makeProduct($store, 100.00);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-discount-2',
            'price' => 140.50,
        ]);

        $product->refresh();

        $this->assertSame(140.50, (float) $product->price);
        $this->assertNull($product->price_old);
    }

    public function test_price_updated_event_clears_stale_price_old_when_promo_ends(): void
    {
        $store = Store::factory()->create();
        // Товар уже был со скидкой (акционная цена в price, старая в price_old).
        $product = $this->makeProduct($store, 79.00, 100.00);

        // 1С перестал слать <ЦенаЗаЕдиницуСоСкидкой> — акция закончилась.
        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-discount-3',
            'price' => 100.00,
        ]);

        $product->refresh();

        $this->assertSame(100.00, (float) $product->price);
        $this->assertNull($product->price_old);
    }
}
