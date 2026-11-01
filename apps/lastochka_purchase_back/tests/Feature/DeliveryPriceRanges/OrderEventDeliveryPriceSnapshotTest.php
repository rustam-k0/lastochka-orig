<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MID-280: приём снимка условий доставки из события заказа витрины (ключ delivery_price_snapshot).
 * Единое правило трёх трансформеров курьерки: массив или JSON-строка массива → атрибут, иначе атрибута
 * нет; снимок пишется один раз, у заказа со снимком обновление ключ не применяет.
 * Входящей очереди заказов у purchase_back нет (config/rabbitmq-events.php, inbound_queues пуст):
 * приём держится ради идентичности трансформеров, поэтому тест повторяет путь пакета событий
 * (EventConsumer: fromEvent() → beforeUpdate() → forceFill()->save()).
 */
class OrderEventDeliveryPriceSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    private const SNAPSHOT = [
        'base_price' => 200,
        'ranges' => [
            ['from_amount' => 1000, 'price' => 100],
            ['from_amount' => 3000, 'price' => 0],
        ],
        'checkout_price' => 100,
    ];

    private const REBUILT_SNAPSHOT = [
        'base_price' => 150,
        'ranges' => [],
        'checkout_price' => 100,
    ];

    private function event(array $extra = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 1600,
        ], $extra);
    }

    /**
     * Путь обновления пакета событий: fromEvent() → beforeUpdate() → forceFill()->save().
     */
    private function applyUpdate(Order $order, array $event): void
    {
        $transformer = new OrderEventTransformer();
        $data = $transformer->beforeUpdate($order, $event, $transformer->fromEvent($event));

        $this->assertNotNull($data, 'beforeUpdate не должен пропускать обновление заказа');

        $order->forceFill($data)->save();
    }

    public function test_snapshot_array_is_taken_as_is(): void
    {
        $attributes = (new OrderEventTransformer())->fromEvent($this->event(['delivery_price_snapshot' => self::SNAPSHOT]));

        $this->assertSame(self::SNAPSHOT, $attributes['delivery_price_snapshot']);
    }

    public function test_snapshot_json_string_is_decoded(): void
    {
        $attributes = (new OrderEventTransformer())->fromEvent($this->event(['delivery_price_snapshot' => json_encode(self::SNAPSHOT)]));

        $this->assertSame(self::SNAPSHOT, $attributes['delivery_price_snapshot']);
    }

    public function test_null_or_garbage_gives_no_attribute(): void
    {
        foreach ([null, '', 'oops', '42', 42, true] as $value) {
            $attributes = (new OrderEventTransformer())->fromEvent($this->event(['delivery_price_snapshot' => $value]));

            $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes, var_export($value, true));
        }
    }

    public function test_missing_key_gives_no_attribute(): void
    {
        $attributes = (new OrderEventTransformer())->fromEvent($this->event());

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    public function test_update_writes_snapshot_to_order_without_one(): void
    {
        $order = Order::factory()->create(['delivery_price' => 100]);

        $this->applyUpdate($order, $this->event(['id' => $order->uuid, 'delivery_price_snapshot' => self::SNAPSHOT]));

        // MySQL JSON переставляет ключи объекта: сравнение без учёта порядка ключей
        $this->assertEquals(self::SNAPSHOT, Order::find($order->id)->delivery_price_snapshot);
    }

    public function test_update_keeps_existing_snapshot(): void
    {
        // Снимок уже пересобран delivery_back (смена магазина), витрина шлёт свой снимок оформления
        $order = Order::factory()->create(['delivery_price' => 100, 'amount' => 1000]);
        $order->forceFill(['delivery_price_snapshot' => self::REBUILT_SNAPSHOT])->save();

        $this->applyUpdate(Order::find($order->id), $this->event([
            'id' => $order->uuid,
            'grand_total' => 1600,
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $fresh = Order::find($order->id);
        $this->assertEquals(self::REBUILT_SNAPSHOT, $fresh->delivery_price_snapshot);
        // Остальное обновление применилось: пропускается только ключ снимка
        $this->assertEquals(1600, (float) $fresh->amount);
    }

    public function test_update_without_key_keeps_snapshot(): void
    {
        $order = Order::factory()->create(['delivery_price' => 100]);
        $order->forceFill(['delivery_price_snapshot' => self::SNAPSHOT])->save();

        $this->applyUpdate(Order::find($order->id), $this->event(['id' => $order->uuid]));

        $this->assertEquals(self::SNAPSHOT, Order::find($order->id)->delivery_price_snapshot);
    }
}
