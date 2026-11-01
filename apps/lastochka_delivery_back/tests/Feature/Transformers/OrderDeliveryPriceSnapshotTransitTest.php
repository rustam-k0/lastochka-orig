<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Приём снимка условий доставки (orders.delivery_price_snapshot, MID-280) из события
 * заказа витрины. Ключ пишется только когда он есть и значение массив (или JSON-строка
 * с массивом): содержимое проверяет читатель (DeliveryPriceSnapshot::fromArray()).
 * Запись один раз: у заказа со снимком обновление ключ не применяет (beforeUpdate).
 */
class OrderDeliveryPriceSnapshotTransitTest extends TestCase
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

    private function transformer(): OrderEventTransformer
    {
        return app(OrderEventTransformer::class);
    }

    private function event(array $extra = []): array
    {
        return array_merge([
            'id' => 'uuid-dps-' . uniqid(),
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 1600,
        ], $extra);
    }

    private function existingOrder(?array $snapshot): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = new Order();
        $order->forceFill([
            'uuid' => 'dps-ex-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 1600,
            'delivery_price' => 100,
            'delivery_price_snapshot' => $snapshot,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ])->save();

        return $order->fresh();
    }

    /**
     * Путь UPDATE консьюмера пакета (EventConsumer: fromEvent → beforeUpdate → forceFill()->save()).
     */
    private function consumeUpdate(Order $order, array $event): void
    {
        $transformer = $this->transformer();
        $data = $transformer->beforeUpdate($order, $event, $transformer->fromEvent($event));

        $order->withoutEvents(function () use ($order, $data) {
            $order->forceFill($data)->save();
        });
    }

    /** @test */
    public function from_event_maps_snapshot(): void
    {
        $attributes = $this->transformer()->fromEvent($this->event([
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $this->assertSame(self::SNAPSHOT, $attributes['delivery_price_snapshot']);
    }

    /** @test */
    public function from_event_decodes_snapshot_from_json_string(): void
    {
        $attributes = $this->transformer()->fromEvent($this->event([
            'delivery_price_snapshot' => json_encode(self::SNAPSHOT),
        ]));

        $this->assertSame(self::SNAPSHOT, $attributes['delivery_price_snapshot']);
    }

    /** @test */
    public function from_event_writes_array_as_is_without_validation(): void
    {
        // Содержимое проверяет читатель: резолвер через DeliveryPriceSnapshot::fromArray()
        $attributes = $this->transformer()->fromEvent($this->event([
            'delivery_price_snapshot' => ['base_price' => 200],
        ]));

        $this->assertSame(['base_price' => 200], $attributes['delivery_price_snapshot']);
    }

    /** @test */
    public function from_event_skips_snapshot_when_absent(): void
    {
        $attributes = $this->transformer()->fromEvent($this->event());

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    /** @test */
    public function from_event_ignores_values_that_are_not_arrays(): void
    {
        foreach (['null' => null, 'broken json' => '{oops', 'plain string' => 'oops', 'json scalar' => '"text"', 'number' => 42] as $case => $value) {
            $attributes = $this->transformer()->fromEvent($this->event(['delivery_price_snapshot' => $value]));

            $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes, $case);
        }
    }

    /** @test */
    public function snapshot_from_event_is_persisted_like_the_consumer_does(): void
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        // Консьюмер пакета пишет атрибуты fromEvent() через forceFill()->save()
        $order = new Order();
        $order->forceFill(array_merge(
            $this->transformer()->fromEvent($this->event(['delivery_price_snapshot' => self::SNAPSHOT])),
            ['client_id' => $clientId]
        ))->save();

        $this->assertEquals(self::SNAPSHOT, $order->fresh()->delivery_price_snapshot);
    }

    /** @test */
    public function update_does_not_overwrite_existing_snapshot(): void
    {
        $stored = ['base_price' => 250, 'ranges' => [['from_amount' => 2000, 'price' => 150]], 'checkout_price' => 100];
        $order = $this->existingOrder($stored);

        $this->consumeUpdate($order, $this->event([
            'id' => $order->uuid,
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $this->assertEquals($stored, $order->fresh()->delivery_price_snapshot);
    }

    /** @test */
    public function update_writes_snapshot_to_order_without_one(): void
    {
        $order = $this->existingOrder(null);

        $this->consumeUpdate($order, $this->event([
            'id' => $order->uuid,
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $this->assertEquals(self::SNAPSHOT, $order->fresh()->delivery_price_snapshot);
    }

    /** @test */
    public function update_without_snapshot_key_keeps_other_fields_flowing(): void
    {
        $order = $this->existingOrder(self::SNAPSHOT);

        $this->consumeUpdate($order, $this->event([
            'id' => $order->uuid,
            'shipping_total' => 0,
            'delivery_price_snapshot' => ['base_price' => 1],
        ]));

        $fresh = $order->fresh();
        $this->assertEquals(self::SNAPSHOT, $fresh->delivery_price_snapshot);
        $this->assertSame(0, (int) $fresh->delivery_price, 'остальные поля события применяются как обычно');
    }

    /** @test */
    public function to_event_does_not_carry_snapshot(): void
    {
        $order = $this->existingOrder(self::SNAPSHOT);

        $event = $this->transformer()->toEvent($order);

        // Итог доставки уходит полем shipping_total, снимок витрине не нужен
        $this->assertArrayNotHasKey('delivery_price_snapshot', $event);
        $this->assertSame(100.0, $event['shipping_total']);
    }
}
