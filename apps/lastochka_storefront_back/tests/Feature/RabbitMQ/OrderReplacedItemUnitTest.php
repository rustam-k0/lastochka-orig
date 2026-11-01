<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\MeasurementUnit;
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
 * MID-267: количество заменённой позиции delivery_back присылает в наименьших
 * единицах (граммы), поэтому и единица хранения должна быть наименьшей.
 *
 * До фикса витрина ставила единицу из карточки товара («кг») к граммовому
 * количеству: позиция превращалась в «1000 кг» на 300 000 ₽, и это же значение
 * уезжало обратно в курьерку, где курьер видел «1000 кг» в составе заказа.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrderReplacedItemUnitTest extends TestCase
{
    use RefreshDatabase;

    private function makeWeightedOrderItem(): array
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $product = Product::factory()->for($store)->create([
            'title' => 'Мясо Производство (куриная грудка)',
            'measurement_unit' => MeasurementUnit::Kilogram,
            'quantity_step' => 1,
        ]);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 300,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => 300,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Мясо Производство (куриная грудка)',
            'price' => 300,
            'quantity' => 1000,
            'measurement_unit' => MeasurementUnit::Gram,
            'total' => 300,
        ]);

        return [$order, $item];
    }

    private function replacedEvent(OrderItem $item): array
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
                [
                    'id' => (string) $item->id,
                    'source' => config('rabbitmq-events.source'),
                    'product_name' => 'Мясо Производство (куриная грудка)',
                    // delivery_back шлёт граммы: 1 порция (кг) × 1000
                    'quantity' => 1000,
                    'unit_price' => 300.0,
                    'unit_price_effective' => 300.0,
                    'line_total' => 0,
                    'item_status' => 'replaced',
                ],
            ],
        ];
    }

    public function test_replaced_weighted_item_keeps_smallest_unit(): void
    {
        [$order, $item] = $this->makeWeightedOrderItem();

        (new OrderEventTransformer)->afterUpdate($order, $this->replacedEvent($item));

        $item->refresh();

        $this->assertSame('replaced', $item->status);
        $this->assertSame(1000, (int) $item->quantity);
        $this->assertSame(
            MeasurementUnit::Gram,
            $item->measurement_unit,
            'Граммовое количество не должно получать метку «кг»'
        );
        $this->assertSame(0.0, round((float) $item->total, 2));
    }

    public function test_replaced_piece_item_keeps_piece_unit(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_001']);
        $user = User::factory()->create();

        $product = Product::factory()->for($store)->create([
            'title' => 'Кофе молотый',
            'measurement_unit' => MeasurementUnit::Piece,
            'quantity_step' => 1,
        ]);

        $order = Order::factory()->for($user)->for($store)->create([
            'status' => OrderStatus::Pending,
            'subtotal' => 1900,
            'discount' => 0,
            'delivery_cost' => 0,
            'total' => 1900,
            'items_count' => 1,
        ]);

        $item = OrderItem::factory()->for($order)->for($product)->create([
            'title' => 'Кофе молотый',
            'price' => 1900,
            'quantity' => 1,
            'measurement_unit' => MeasurementUnit::Piece,
            'total' => 1900,
        ]);

        $event = $this->replacedEvent($item);
        $event['items'][0]['product_name'] = 'Кофе молотый';
        $event['items'][0]['quantity'] = 1;
        $event['items'][0]['unit_price'] = 1900.0;
        $event['items'][0]['unit_price_effective'] = 1900.0;

        (new OrderEventTransformer)->afterUpdate($order, $event);

        $item->refresh();

        $this->assertSame('replaced', $item->status);
        $this->assertSame(MeasurementUnit::Piece, $item->measurement_unit);
        $this->assertSame(1, (int) $item->quantity);
    }
}
