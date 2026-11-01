<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryFinish;
use App\GraphQL\Mutations\Delivery\DeliveryTakeOrder;
use App\Models\Order;
use App\Models\Status;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T7: orders.finished_at при завершении доставки.
 * - finish (переход в ORDER_FINISHED) ставит finished_at;
 * - отмена (allReturned → ORDER_CANCELLED) НЕ ставит;
 * - повторный finish не перезаписывает (заказ мог быть завершён другим курьером);
 * - подзаказы при сплите тоже получают finished_at.
 */
class DeliveryFinishFinishedAtTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $fcm = $this->mock(FcmNotificationService::class);
        $fcm->shouldReceive('sendToUser')->andReturnNull();
        $fcm->shouldReceive('sendToUsers')->andReturnNull();

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Finished-at shop', 'id_1c' => random_int(900000, 999999), 'default' => 0,
            'slot_max_orders' => 10, 'slot_time' => 60, 'is_popular' => 0,
            'is_pickup_enabled' => 0, 'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0, 'is_bonus_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // orders.client_id — NOT NULL FK на clients (clients в mendev_test пуст).
        $this->clientId = \DB::table('clients')->insertGetId([
            'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function insertOrder(array $attrs = []): int
    {
        return \DB::table('orders')->insertGetId(array_merge([
            'shop_id' => $this->shopId, 'client_id' => $this->clientId, 'uuid' => 'dfa-' . uniqid(),
            'discount' => 0, 'payment_type_id' => 1, 'paid' => 0, 'type_id' => 2,
            'status_id' => Status::ORDER_ASSEMBLED, 'amount' => 100, 'is_heavy' => 0,
            'slot_start_date' => now(), 'slot_end_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function insertCourier(string $name): int
    {
        return \DB::table('users')->insertGetId([
            'name' => $name, 'surname' => 'Курьеров', 'password' => 'test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Один курьер взял заказ и стартовал доставку. @return \App\Models\DeliveryOrder */
    private function takeAndStart(int $orderId): \App\Models\DeliveryOrder
    {
        $courierId = $this->insertCourier('Курьер');
        $res = DeliveryTakeOrder::takeOrder($courierId, $orderId);
        $res['delivery']->update(['started_at' => now()]);
        $res['delivery_order']->update([
            'status_id' => Status::DELIVERY_IN_PROGRESS,
            'started_at' => now(),
        ]);
        Order::whereId($orderId)->update(['status_id' => Status::ORDER_IN_DELIVERY]);

        return $res['delivery_order']->fresh();
    }

    /** @test */
    public function finish_sets_orders_finished_at(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $orderId = $this->insertOrder();
        $do = $this->takeAndStart($orderId);

        DeliveryFinish::deliveryFinish($do, $do->delivery);

        $order = Order::find($orderId);
        $this->assertSame(Status::ORDER_FINISHED, (int) $order->status_id);
        $this->assertNotNull($order->finished_at, 'finished_at должен проставиться при завершении');
        $this->assertSame('2026-07-10 10:00:00', (string) $order->getRawOriginal('finished_at'));
    }

    /** @test */
    public function full_return_cancel_does_not_set_finished_at(): void
    {
        $orderId = $this->insertOrder();
        $do = $this->takeAndStart($orderId);

        // Единственная позиция полностью возвращена → applyCourierReturns вернёт allReturned=true,
        // заказ трактуется как отменённый (ORDER_CANCELLED), finished_at ставить нельзя.
        $productId = \DB::table('products')->insertGetId([
            'title' => 'Full return product', 'articul' => 'ART-FR-' . uniqid(),
            'unit_type_id' => 1, 'info_weight' => 100, 'info_weight_type_id' => 2,
            'status_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId,
            'quantity' => 2, 'price' => 100, 'price_during_order' => 100,
            'returned_quantity' => 2,
            'status_id' => Status::ORDER_ITEM_ASSEMBLED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DeliveryFinish::deliveryFinish($do, $do->delivery);

        $order = Order::find($orderId);
        $this->assertSame(Status::ORDER_CANCELLED, (int) $order->status_id);
        $this->assertNull($order->finished_at, 'при отмене finished_at ставить нельзя');
    }

    /** @test */
    public function repeated_finish_does_not_overwrite_finished_at(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $orderId = $this->insertOrder();
        $do = $this->takeAndStart($orderId);

        DeliveryFinish::deliveryFinish($do, $do->delivery);
        $firstFinishedAt = Order::find($orderId)->getRawOriginal('finished_at');
        $this->assertSame('2026-07-10 10:00:00', (string) $firstFinishedAt);

        // Совместная доставка: другой курьер «завершает» тот же заказ позже —
        // finished_at не должен перезаписаться. Рейс уже помечен finished и скрыт
        // global scope NotFinished, поэтому достаём его напрямую.
        Carbon::setTestNow('2026-07-10 12:30:00');
        $doFresh = $do->fresh();
        $delivery = \App\Models\Delivery::withoutGlobalScopes()->find($doFresh->delivery_id);
        DeliveryFinish::deliveryFinish($doFresh, $delivery);

        $this->assertSame(
            (string) $firstFinishedAt,
            (string) Order::find($orderId)->getRawOriginal('finished_at'),
            'повторный finish не должен перезаписывать finished_at'
        );
    }

    /** @test */
    public function split_children_get_finished_at(): void
    {
        Carbon::setTestNow('2026-07-10 09:15:00');

        $parentId = $this->insertOrder();
        $childId = $this->insertOrder([
            'parent_order_id' => $parentId,
            'sub_number' => 1,
            'status_id' => Status::ORDER_IN_DELIVERY,
        ]);

        $do = $this->takeAndStart($parentId);

        DeliveryFinish::deliveryFinish($do, $do->delivery);

        $parent = Order::find($parentId);
        $child = Order::find($childId);

        $this->assertSame(Status::ORDER_FINISHED, (int) $parent->status_id);
        $this->assertSame(Status::ORDER_FINISHED, (int) $child->status_id);
        $this->assertNotNull($parent->finished_at, 'у родителя должен быть finished_at');
        $this->assertNotNull($child->finished_at, 'у подзаказа должен быть finished_at');
        $this->assertSame('2026-07-10 09:15:00', (string) $child->getRawOriginal('finished_at'));
    }
}
