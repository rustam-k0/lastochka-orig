<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Delivery\DeliveryLogDistance;
use App\GraphQL\Validators\Delivery\DeliveryLogDistanceValidator;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Приём агрегата пробега за доставку (LASTOCHKI-JAMI-11595, #11602 P2).
 *
 * Мутация DeliveryLogDistance публикует delivery.distance_logged —
 * источник метрики «пробег за смену» курьера. Работает и для уже
 * завершённой доставки (приложение шлёт агрегат постфактум).
 */
class DeliveryLogDistanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $shopId;
    private int $clientId;
    private int $courierId;
    private int $shiftId;
    private int $orderId;
    private int $deliveryId;
    private int $deliveryOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = \DB::table('clients')->insertGetId([
            'is_authorized' => 1,
            'newsletter_subscribed' => 0,
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shopId = \DB::table('shops')->insertGetId([
            'title' => 'Distance log shop',
            'code' => 'distance-log-shop',
            'id_1c' => random_int(900000, 999999),
            'default' => 0,
            'slot_max_orders' => 10,
            'slot_time' => 60,
            'is_popular' => 0,
            'is_pickup_enabled' => 0,
            'pickup_auto_cancel_enabled' => 0,
            'ignore_stock' => 0,
            'is_bonus_enabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->courierId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->shiftId = \DB::table('shifts')->insertGetId([
            'user_id' => $this->courierId,
            'shop_id' => $this->shopId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->orderId = \DB::table('orders')->insertGetId([
            'shop_id' => $this->shopId,
            'uuid' => 'dist-' . uniqid(),
            'discount' => 0,
            'payment_type_id' => 1,
            'paid' => 0,
            'type_id' => 2,
            'client_id' => $this->clientId,
            'status_id' => Status::ORDER_IN_DELIVERY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Доставка уже завершена: агрегат пробега приходит постфактум
        $this->deliveryId = \DB::table('deliveries')->insertGetId([
            'user_id' => $this->courierId,
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deliveryOrderId = \DB::table('delivery_orders')->insertGetId([
            'delivery_id' => $this->deliveryId,
            'order_id' => $this->orderId,
            'status_id' => Status::DELIVERY_FINISHED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_distance_logged_event_is_published_for_finished_delivery(): void
    {
        $this->actingAs(User::find($this->courierId), 'api');

        $result = (new DeliveryLogDistance())->__invoke(null, [
            'delivery_order_id' => $this->deliveryOrderId,
            'distance_m' => 3800,
        ]);

        $this->assertSame(200, $result['code']);

        $row = \DB::table('event_outbox')
            ->where('routing_key', 'delivery.distance_logged')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($row, 'Событие delivery.distance_logged не попало в outbox');

        $payload = json_decode($row->payload, true);
        $orderUuid = \DB::table('orders')->where('id', $this->orderId)->value('uuid');

        $this->assertSame($orderUuid, $payload['order_id']);
        $this->assertSame($this->courierId, $payload['courier_id']);
        $this->assertSame('shift-' . $this->shiftId, $payload['session_id']);
        $this->assertSame($this->deliveryId, $payload['delivery_id']);
        $this->assertSame(3800, $payload['distance_m']);
        $this->assertSame('distance-log-shop', $payload['shop_code']);
    }

    public function test_validator_rejects_foreign_delivery_order_and_negative_distance(): void
    {
        $foreignCourierId = \DB::table('users')->insertGetId([
            'password' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::find($foreignCourierId), 'api');

        $rules = (new DeliveryLogDistanceValidator())->rules();

        $foreign = Validator::make([
            'delivery_order_id' => $this->deliveryOrderId,
            'distance_m' => 100,
        ], $rules);
        $this->assertTrue($foreign->fails(), 'Чужой delivery_order должен быть отвергнут');

        $this->actingAs(User::find($this->courierId), 'api');

        $rules = (new DeliveryLogDistanceValidator())->rules();

        $negative = Validator::make([
            'delivery_order_id' => $this->deliveryOrderId,
            'distance_m' => -5,
        ], $rules);
        $this->assertTrue($negative->fails(), 'Отрицательный пробег должен быть отвергнут');

        $own = Validator::make([
            'delivery_order_id' => $this->deliveryOrderId,
            'distance_m' => 100,
        ], $rules);
        $this->assertTrue($own->passes(), 'Свой delivery_order (в т.ч. завершённый) должен проходить');
    }
}
