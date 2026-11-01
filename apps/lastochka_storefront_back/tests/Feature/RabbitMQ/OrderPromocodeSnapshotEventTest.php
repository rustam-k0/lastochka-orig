<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Enums\PromocodeType;
use App\Models\Order;
use App\Models\OrderPromocode;
use App\Models\Store;
use App\Models\User;
use App\RabbitMQ\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Событие заказа несёт снапшот промокода: тип и номинал, а не только процент.
 *
 * Курьерка пересчитывает скидку после сборки. Без типа и номинала фиксированный
 * промокод был для неё неотличим от «скидки неизвестной природы» (discount_percent
 * у fixed пуст) и терялся при сплите по зонам сборки — прод-заказы 200 и 207
 * (2026-09-10) ушли в курьерку и в чек на 300 ₽ дороже, чем видел клиент.
 */
final class OrderPromocodeSnapshotEventTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $store = Store::factory()->create(['code' => 'PROMO_SNAPSHOT']);
        $user = User::factory()->create();

        return Order::factory()->for($user)->for($store)->create([
            'subtotal' => 1000,
            'discount' => 300,
            'delivery_cost' => 100,
            'total' => 800,
        ]);
    }

    public function test_fixed_promocode_snapshot_is_published(): void
    {
        $order = $this->makeOrder();

        OrderPromocode::factory()->for($order)->create([
            'code' => 'ЛАСТОЧКА300',
            'type' => PromocodeType::Fixed,
            'value' => 300,
            'discount_amount' => 300,
        ]);

        $event = (new OrderEventTransformer())->toEvent($order->fresh());

        $this->assertSame('ЛАСТОЧКА300', $event['promocode']['code']);
        $this->assertSame('fixed', $event['promocode']['discount_type']);
        $this->assertSame(300, $event['promocode']['discount_value']);
        $this->assertNull($event['promocode']['discount_percent']);
    }

    public function test_percent_promocode_snapshot_is_published(): void
    {
        $order = $this->makeOrder();

        OrderPromocode::factory()->for($order)->create([
            'code' => 'SALE10',
            'type' => PromocodeType::Percent,
            'value' => 10,
            'discount_amount' => 100,
        ]);

        $event = (new OrderEventTransformer())->toEvent($order->fresh());

        $this->assertSame('percent', $event['promocode']['discount_type']);
        $this->assertSame(10, $event['promocode']['discount_value']);
        $this->assertSame(10, $event['promocode']['discount_percent']);
    }

    public function test_order_without_promocode_publishes_null(): void
    {
        $event = (new OrderEventTransformer())->toEvent($this->makeOrder()->fresh());

        $this->assertNull($event['promocode']);
    }
}
