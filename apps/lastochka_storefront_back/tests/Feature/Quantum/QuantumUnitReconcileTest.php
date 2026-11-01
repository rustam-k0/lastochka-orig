<?php

declare(strict_types=1);

namespace Tests\Feature\Quantum;

use App\Models\Product;
use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Согласование кванта при приёме событий, не знающих про шаг продажи.
 *
 * Шаг продажи (quantity_step) задаётся в админке и приезжает только в событиях
 * admin_back. 1c_parser шлёт карточку в базовых единицах 1С: measurement_unit=
 * kilogram и цена за килограмм, ключа quantity_step в событии нет. Применённое
 * «как есть», такое событие оставляет товар несогласованным — единица kilogram
 * при шаге 500 г — и ломает две вещи:
 *   1) вес позиции заказа: CreateOrderAction считал корзину килограммами и
 *      множил на 1000 (прод-заказ 204 от 10.09.2026: 1 кг огурцов → 1000 кг);
 *   2) цену: 290 ₽ за кг подписывалось как цена за 500 г (клиент платит вдвое).
 *
 * Ожидание: событие приводится к кванту товара — единица к базовой (кг→г),
 * цена к цене за квант по формуле admin_back CategoryQuantumService:
 * round(цена_за_кг × шаг / 1000, 2).
 */
final class QuantumUnitReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuantumProduct(int $step = 500, float $price = 145.0): Product
    {
        return Product::factory()->create([
            'store_id' => Store::factory()->create()->id,
            'sku' => 'CUCUMBER-95700',
            'price' => $price,
            'quantity' => 10000,
            'quantity_step' => $step,
            'measurement_unit' => 'gram',
            'unit_type' => 'kg',
            'is_active' => true,
        ]);
    }

    /**
     * Полная карточка из 1С (product.updated): kilogram + цена за кг, без шага.
     */
    public function test_full_event_without_step_keeps_gram_and_scales_price(): void
    {
        $product = $this->makeQuantumProduct();

        $transformer = app(ProductEventTransformer::class);
        $transformed = $transformer->fromEvent([
            'id' => 'guid-cucumber',
            'shop_code' => $product->store->code,
            'source' => '1c',
            'sku' => '95700',
            'title' => 'Огурцы Эстафета',
            'price' => 290,
            'quantity' => 6.896,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
        ]);

        $result = $transformer->beforeUpdate($product, [
            'measurement_unit' => 'kilogram',
            'unit_type' => 'kg',
            'price' => 290,
        ], $transformed);

        $this->assertSame('gram', $result['measurement_unit'], 'шаг 500 г остаётся — единица не может стать килограммом');
        $this->assertSame(145.0, (float) $result['price'], '290 ₽/кг → 145 ₽ за квант 500 г');
    }

    /**
     * Акционная цена: price_old (базовая) масштабируется вместе с price.
     */
    public function test_discount_price_and_price_old_are_both_scaled(): void
    {
        $product = $this->makeQuantumProduct(step: 200);

        $transformer = app(ProductEventTransformer::class);
        $transformed = $transformer->fromEvent([
            'id' => 'guid-mushroom',
            'shop_code' => $product->store->code,
            'source' => '1c',
            'sku' => '37872',
            'title' => 'Грибы Шампиньоны',
            'price' => 280,
            'discount_price' => 240,
            'quantity' => 67.5,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
        ]);

        $result = $transformer->beforeUpdate($product, [
            'measurement_unit' => 'kilogram',
            'price' => 280,
            'discount_price' => 240,
        ], $transformed);

        $this->assertSame('gram', $result['measurement_unit']);
        $this->assertSame(48.0, (float) $result['price'], '240 ₽/кг → 48 ₽ за квант 200 г');
        $this->assertSame(56.0, (float) $result['price_old'], '280 ₽/кг → 56 ₽ за квант 200 г');
    }

    /**
     * Событие admin_back несёт свой шаг — оно самодостаточно, не трогаем.
     */
    public function test_event_with_own_step_is_applied_as_is(): void
    {
        $product = $this->makeQuantumProduct();

        $transformer = app(ProductEventTransformer::class);
        $eventData = [
            'id' => 'guid-cucumber',
            'shop_code' => $product->store->code,
            'source' => 'app',
            'sku' => '95700',
            'title' => 'Огурцы Эстафета',
            'price' => 174,
            'quantity' => 6.896,
            'unit_type' => 'kg',
            'measurement_unit' => 'gram',
            'quantity_step' => 600,
        ];

        $result = $transformer->beforeUpdate($product, $eventData, $transformer->fromEvent($eventData));

        $this->assertSame('gram', $result['measurement_unit']);
        $this->assertSame(600, (int) $result['quantity_step']);
        $this->assertSame(174.0, (float) $result['price'], 'цена за квант из админки применяется без пересчёта');
    }

    /**
     * Кг-товар без кванта (шаг 1): единица и цена за кг — норма, не трогаем.
     */
    public function test_product_without_step_keeps_kilogram_and_price(): void
    {
        $product = Product::factory()->create([
            'store_id' => Store::factory()->create()->id,
            'price' => 677,
            'quantity_step' => 1,
            'measurement_unit' => 'kilogram',
            'unit_type' => 'kg',
        ]);

        $transformer = app(ProductEventTransformer::class);
        $eventData = [
            'id' => 'guid-sausage',
            'shop_code' => $product->store->code,
            'source' => '1c',
            'sku' => '67799',
            'title' => 'Сосиски Сливочные',
            'price' => 677,
            'quantity' => 0.606,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
        ];

        $result = $transformer->beforeUpdate($product, $eventData, $transformer->fromEvent($eventData));

        $this->assertSame('kilogram', $result['measurement_unit']);
        $this->assertSame(677.0, (float) $result['price']);
    }

    /**
     * price_updated из 1С по квантованному товару: цена за кг → цена за квант,
     * единица не сползает в килограммы.
     */
    public function test_price_updated_without_step_scales_price_to_quantum(): void
    {
        $product = $this->makeQuantumProduct(step: 200, price: 124.80);

        app(ProductEventTransformer::class)->processPriceUpdatedEvent($product, [
            'event_id' => 'evt-cheese',
            'price' => 624,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
        ]);

        $product->refresh();

        $this->assertSame(124.80, (float) $product->price, '624 ₽/кг → 124.80 ₽ за квант 200 г');
        $this->assertSame('gram', $product->measurement_unit->value);
        $this->assertSame(200, (int) $product->quantity_step);
    }

    /**
     * Цена за квант — та же, что считает admin_back: от цены за кг без
     * промежуточного округления цены за грамм (594.55 × 0.3 = 178.365 → 178.37;
     * через ₽/г до 4 знаков выходило 178.38, и эхо админки расходилось на копейку).
     */
    public function test_quantum_price_keeps_kopecks_like_admin(): void
    {
        $product = $this->makeQuantumProduct(step: 300, price: 178.37);

        $transformer = app(ProductEventTransformer::class);
        $transformed = $transformer->fromEvent([
            'id' => 'guid-fillet',
            'shop_code' => $product->store->code,
            'source' => '1c',
            'sku' => '77203',
            'title' => 'Филе грудки',
            'price' => 594.55,
            'quantity' => 12.806,
            'unit_type' => 'kg',
            'measurement_unit' => 'kilogram',
        ]);

        $result = $transformer->beforeUpdate($product, [
            'measurement_unit' => 'kilogram',
            'unit_type' => 'kg',
            'price' => 594.55,
        ], $transformed);

        $this->assertSame(178.37, (float) $result['price']);
    }
}
