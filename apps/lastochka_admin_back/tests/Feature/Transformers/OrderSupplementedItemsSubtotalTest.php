<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Database\Factories\ShopFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * MEN-IN-DEV-11267: items_subtotal (сумма товаров до скидок) должен
 * обновляться при получении supplemented-события через processSupplementedEvent().
 * Ранее это поле игнорировалось в updateData — тест проверяет что фикс работает.
 */
class OrderSupplementedItemsSubtotalTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer();
    }

    private function makeShop(): \App\Models\Shop
    {
        return ShopFactory::new()->create([
            'code'            => 'supplemented-' . uniqid(),
            'title'           => 'Supplemented Test Shop',
            'commission'      => 0,
            'deposit'         => 0,
            'payment_type_id' => Type::PAYMENT_CASH,
            'slot_time'       => 30,
            'slot_max_orders' => 10,
            'type_id'         => Type::DELIVERY_COURIER,
            'longitude'       => 37.6,
            'latitude'        => 55.7,
        ]);
    }

    private function makeClient(): Client
    {
        return Client::create([
            'phone'         => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);
    }

    private function makeOrder(\App\Models\Shop $shop, Client $client, array $extra = []): Order
    {
        return Order::withoutEvents(fn () => Order::create(array_merge([
            'uuid'            => 'supplemented-test-' . uniqid(),
            'client_id'       => $client->id,
            'shop_id'         => $shop->id,
            'type_id'         => Type::DELIVERY_COURIER,
            'status_id'       => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount'          => 900.00,
            'origin_amount'   => 850.00,
            'paid'            => false,
            'slot_start_date' => now(),
            'slot_end_date'   => now(),
            'items_subtotal'  => 800.00,
        ], $extra)));
    }

    public function test_supplemented_event_updates_items_subtotal(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();
        $order  = $this->makeOrder($shop, $client);

        // Убедимся что исходное значение корректное
        $this->assertEquals(800.00, (float) $order->items_subtotal);

        // Вызовем processSupplementedEvent с новым items_subtotal
        $this->transformer->processSupplementedEvent($order, [
            'items_subtotal' => 1500.75,
        ]);

        // Перезагрузим заказ из БД и проверим что значение обновилось
        $refreshedOrder = $order->fresh();
        $this->assertEquals(1500.75, (float) $refreshedOrder->items_subtotal);
    }

    public function test_supplemented_event_without_items_subtotal_leaves_it_unchanged(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();
        $order  = $this->makeOrder($shop, $client);

        // Исходное значение
        $this->assertEquals(800.00, (float) $order->items_subtotal);

        // Вызовем processSupplementedEvent БЕЗ items_subtotal в eventData
        $this->transformer->processSupplementedEvent($order, [
            // items_subtotal отсутствует
            'grand_total' => 900.00,
        ]);

        // Значение должно остаться неизменным
        $refreshedOrder = $order->fresh();
        $this->assertEquals(800.00, (float) $refreshedOrder->items_subtotal);
    }

    public function test_supplemented_event_preserves_kopecks_in_items_subtotal(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();
        $order  = $this->makeOrder($shop, $client);

        // items_subtotal — decimal(15,2), должен сохранять копейки
        $this->transformer->processSupplementedEvent($order, [
            'items_subtotal' => 2345.67,
        ]);

        $refreshedOrder = $order->fresh();
        $this->assertEquals(2345.67, (float) $refreshedOrder->items_subtotal);
    }

    public function test_supplemented_event_updates_multiple_fields_including_items_subtotal(): void
    {
        $shop   = $this->makeShop();
        $client = $this->makeClient();
        $order  = $this->makeOrder($shop, $client);

        // Обновим несколько полей вместе, включая items_subtotal
        $this->transformer->processSupplementedEvent($order, [
            'grand_total'                 => 1000.00,
            'items_total_after_discount'  => 950.00,
            'items_subtotal'              => 1200.00,
            'shipping_total'              => 100.00,
        ]);

        $refreshedOrder = $order->fresh();

        // Проверим что все поля обновились корректно
        $this->assertEquals(1000.00, (float) $refreshedOrder->amount);
        $this->assertEquals(950.00, (float) $refreshedOrder->origin_amount);
        $this->assertEquals(1200.00, (float) $refreshedOrder->items_subtotal);
        $this->assertEquals(100.00, (float) $refreshedOrder->delivery_price);
    }
}
