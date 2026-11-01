<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * MID-246: actual_weight в событии order.updated не должен переводить ШТУЧНУЮ
 * позицию в граммовое хранение.
 *
 * До фикса: item с actual_weight=200 для piece-товара получал quantity=200,
 * measurement_unit=gram и total из line_total (пересчитанного delivery_back
 * «по весу» — 1900 × 200 = 380 000). Клиент видел «200 шт» и итог 380 000 ₽,
 * а «Повторить заказ» клал 200 штук в корзину.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderConsumerActualWeightTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrderWithItem(array $productAttrs, array $itemAttrs): array
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();
        $product = Product::factory()->for($store)->create($productAttrs);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'discount' => 0,
            'delivery_cost' => 0,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create($itemAttrs);

        return [$order, $item, $product];
    }

    private function eventData(OrderItem $item, Product $product, array $itemOverrides): array
    {
        return [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'updated',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'delivery_order_uuid',
            'source' => 'delivery',
            'shop_code' => 'MSK_001',
            'status' => 'assembled',
            'items' => [
                array_merge([
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_id' => (string) $product->id,
                    'product_source' => config('rabbitmq-events.source'),
                    'product_name' => $item->title,
                ], $itemOverrides),
            ],
        ];
    }

    public function test_actual_weight_on_piece_product_does_not_switch_to_grams(): void
    {
        // Кейс заказа 13: кофе 1900 ₽ × 1 шт, сборщик прислал «вес» 200 гр,
        // непофикшенный delivery_back пересчитал line_total по весу в 380 000.
        [$order, $item, $product] = $this->makeOrderWithItem(
            ['measurement_unit' => 'piece', 'quantity_step' => 1],
            ['title' => 'Кофе молотый, 227 гр', 'price' => 1900, 'quantity' => 1, 'total' => 1900, 'measurement_unit' => 'piece'],
        );

        (new OrderEventTransformer)->afterUpdate($order, $this->eventData($item, $product, [
            'quantity' => 1,
            'unit_price' => 1900.0,
            'unit_price_effective' => 1900.0,
            'line_subtotal' => 380000.0,
            'line_total' => 380000.0,
            'actual_weight' => 200,
            'item_status' => 'assembled',
        ]));

        $item->refresh();
        $this->assertEquals(1, $item->quantity);
        $this->assertEquals('piece', $item->measurement_unit?->value);
        $this->assertEquals(1900, $item->total);

        $order->refresh();
        $this->assertEquals(1900, $order->subtotal);
    }

    public function test_actual_weight_on_weight_product_keeps_gram_storage(): void
    {
        // Регресс: весовой товар (кг) — фактический вес пишется в граммах,
        // total берётся из line_total, пересчитанного delivery_back по весу.
        [$order, $item, $product] = $this->makeOrderWithItem(
            ['measurement_unit' => 'kilogram', 'quantity_step' => 1],
            ['title' => 'Яблоко красное, кг', 'price' => 125, 'quantity' => 1000, 'total' => 125, 'measurement_unit' => 'gram'],
        );

        (new OrderEventTransformer)->afterUpdate($order, $this->eventData($item, $product, [
            'quantity' => 1,
            'unit_price' => 125.0,
            'unit_price_effective' => 125.0,
            'line_subtotal' => 106.25,
            'line_total' => 106.25,
            'actual_weight' => 850,
            'item_status' => 'assembled',
        ]));

        $item->refresh();
        $this->assertEquals(850, $item->quantity);
        $this->assertEquals('gram', $item->measurement_unit?->value);
        $this->assertEquals(850, $item->actual_weight);
        $this->assertEquals(106.25, $item->total);

        $order->refresh();
        $this->assertEquals(106.25, $order->subtotal);
    }

    public function test_actual_volume_on_liter_product_is_stored_in_milliliters(): void
    {
        // Фактический объём объёмного товара — в мл: единица хранения позиции
        // milliliter, а не gram («846 л» у клиента, кг в чеке).
        [$order, $item, $product] = $this->makeOrderWithItem(
            ['measurement_unit' => 'liter', 'quantity_step' => 1],
            ['title' => 'Молоко разливное, л', 'price' => 100, 'quantity' => 1000, 'total' => 100, 'measurement_unit' => 'milliliter'],
        );

        (new OrderEventTransformer)->afterUpdate($order, $this->eventData($item, $product, [
            'quantity' => 1,
            'unit_price' => 100.0,
            'unit_price_effective' => 100.0,
            'line_subtotal' => 84.6,
            'line_total' => 84.6,
            'actual_weight' => 846,
            'item_status' => 'assembled',
        ]));

        $item->refresh();
        $this->assertEquals(846, $item->quantity);
        $this->assertEquals('milliliter', $item->measurement_unit?->value);
    }

    public function test_added_piece_item_with_actual_weight_is_not_stored_in_grams(): void
    {
        // Ветка создания позиции (добавленный сборщиком товар): штучный товар
        // с actual_weight не должен превращаться в граммовую позицию.
        [$order, $item, $product] = $this->makeOrderWithItem(
            ['measurement_unit' => 'piece', 'quantity_step' => 1],
            ['title' => 'Кофе молотый, 227 гр', 'price' => 1900, 'quantity' => 1, 'total' => 1900, 'measurement_unit' => 'piece'],
        );

        $addedProduct = Product::factory()->for($product->store)->create([
            'title' => 'Шоколад плиточный',
            'measurement_unit' => 'piece',
            'quantity_step' => 1,
        ]);

        $event = $this->eventData($item, $product, [
            'quantity' => 1,
            'unit_price' => 1900.0,
            'unit_price_effective' => 1900.0,
            'line_subtotal' => 1900.0,
            'line_total' => 1900.0,
            'item_status' => 'assembled',
        ]);

        $event['items'][] = [
            'id' => 'ext-added-1',
            'source' => 'delivery',
            'product_id' => (string) $addedProduct->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => 'Шоколад плиточный',
            'quantity' => 2,
            'unit_price' => 300.0,
            'unit_price_effective' => 300.0,
            'line_subtotal' => 120000.0,
            'line_total' => 120000.0,
            'actual_weight' => 200,
            'item_status' => 'added',
        ];

        (new OrderEventTransformer)->afterUpdate($order, $event);

        $added = $order->items()->where('title', 'Шоколад плиточный')->firstOrFail();
        $this->assertEquals(2, $added->quantity);
        $this->assertEquals('piece', $added->measurement_unit?->value);
        $this->assertEquals(600, $added->total);
    }

    public function test_added_item_inherits_product_vat_rate(): void
    {
        // Позиция, добавленная сборщиком, должна нести ставку НДС товара: без неё
        // чек АТОЛ пробивал товар со ставкой из настроек интеграции («без НДС»).
        [$order, $item, $product] = $this->makeOrderWithItem(
            ['measurement_unit' => 'piece', 'quantity_step' => 1, 'vat_rate' => 11],
            ['title' => 'Хлеб белый', 'price' => 60, 'quantity' => 1, 'total' => 60, 'measurement_unit' => 'piece', 'vat_rate' => 11],
        );

        $addedProduct = Product::factory()->for($product->store)->create([
            'title' => 'Молоко 2,5%',
            'measurement_unit' => 'piece',
            'quantity_step' => 1,
            'vat_rate' => 3,
        ]);

        $event = $this->eventData($item, $product, [
            'quantity' => 1,
            'unit_price' => 60.0,
            'unit_price_effective' => 60.0,
            'line_subtotal' => 60.0,
            'line_total' => 60.0,
            'item_status' => 'assembled',
        ]);

        $event['items'][] = [
            'id' => 'ext-added-1',
            'source' => 'delivery',
            'product_id' => (string) $addedProduct->id,
            'product_source' => config('rabbitmq-events.source'),
            'product_name' => 'Молоко 2,5%',
            'quantity' => 1,
            'unit_price' => 120.0,
            'unit_price_effective' => 120.0,
            'line_subtotal' => 120.0,
            'line_total' => 120.0,
            'item_status' => 'added',
        ];

        (new OrderEventTransformer)->afterUpdate($order, $event);

        $added = $order->items()->where('title', 'Молоко 2,5%')->firstOrFail();
        $this->assertSame(3, $added->vat_rate);
    }
}
