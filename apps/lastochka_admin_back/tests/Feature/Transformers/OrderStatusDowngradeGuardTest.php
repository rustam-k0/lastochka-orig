<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Models\Status;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Guard от отката статуса заказа входящим order.updated (Platrum #12213).
 *
 * Инцидент: продавец создал инвойс в Bagisto ПОСЛЕ завершения сборки →
 * Bagisto ядро пересчитало статус заказа в processing → эхо order.updated
 * со status=assembling откатило заказ курьерки «Собрано» → «Заказ на сборке».
 * Сборка при этом уже finished → повторное «Списать оплату» в приложении
 * сборщика падает «Сборка не найдена» (зомби-состояние заказа).
 *
 * Правило: статус по событию может двигаться только вперёд по пайплайну
 * created → assembling(→cooking) → assembled → in_delivery → delivered.
 * cancelled принимается из любого состояния; CANCELLED и FINISHED —
 * терминальные, из них событие статус не меняет (кроме FINISHED→cancelled).
 */
class OrderStatusDowngradeGuardTest extends TestCase
{
    use DatabaseTransactions;

    private OrderEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderEventTransformer;
        Log::spy();
    }

    private function persistOrder(int $statusId): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        return Order::withoutEvents(fn () => Order::create([
            'uuid' => 'sdg-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => $statusId,
            'type_id' => 2,
            'payment_type_id' => 1,
        ]));
    }

    /**
     * Прогнать beforeUpdate с входящим статусом и вернуть итоговые атрибуты.
     */
    private function runGuard(Order $order, string $incomingStatus, array $extra = []): ?array
    {
        $eventData = array_merge([
            'event_id' => 'sdg-' . uniqid(),
            'status' => $incomingStatus,
        ], $extra);

        $transformedData = array_merge([
            'status_id' => $this->statusIdFor($incomingStatus),
            'amount' => 5670.0,
        ], $extra);

        return $this->transformer->beforeUpdate($order, $eventData, $transformedData);
    }

    private function statusIdFor(string $external): int
    {
        return match ($external) {
            'created' => Status::ORDER_CREATED,
            'assembling' => Status::ORDER_PREPARING,
            'assembled' => Status::ORDER_ASSEMBLED,
            'in_delivery' => Status::ORDER_IN_DELIVERY,
            'delivered' => Status::ORDER_FINISHED,
            'cancelled' => Status::ORDER_CANCELLED,
        };
    }

    public function test_assembling_does_not_downgrade_assembled_order(): void
    {
        // Точный сценарий инцидента: заказ собран, эхо Bagisto шлёт assembling
        $order = $this->persistOrder(Status::ORDER_ASSEMBLED);

        $result = $this->runGuard($order, 'assembling');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status_id', $result);
        // Остальные поля события применяются как раньше
        $this->assertSame(5670.0, $result['amount']);
    }

    public function test_assembling_does_not_downgrade_in_delivery_order(): void
    {
        $order = $this->persistOrder(Status::ORDER_IN_DELIVERY);

        $result = $this->runGuard($order, 'assembling');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status_id', $result);
    }

    public function test_created_does_not_downgrade_preparing_order(): void
    {
        $order = $this->persistOrder(Status::ORDER_PREPARING);

        $result = $this->runGuard($order, 'created');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status_id', $result);
    }

    public function test_cancelled_is_allowed_from_assembled(): void
    {
        // Отмена клиентом/маркетплейсом — легитимный переход из любого статуса
        $order = $this->persistOrder(Status::ORDER_ASSEMBLED);

        $result = $this->runGuard($order, 'cancelled');

        $this->assertIsArray($result);
        $this->assertSame(Status::ORDER_CANCELLED, $result['status_id']);
    }

    public function test_forward_transition_assembled_is_allowed_from_preparing(): void
    {
        $order = $this->persistOrder(Status::ORDER_PREPARING);

        $result = $this->runGuard($order, 'assembled');

        $this->assertIsArray($result);
        $this->assertSame(Status::ORDER_ASSEMBLED, $result['status_id']);
    }

    public function test_same_status_passes_through(): void
    {
        $order = $this->persistOrder(Status::ORDER_ASSEMBLED);

        $result = $this->runGuard($order, 'assembled');

        $this->assertIsArray($result);
        $this->assertSame(Status::ORDER_ASSEMBLED, $result['status_id']);
    }

    public function test_cancelled_order_is_not_resurrected_by_event(): void
    {
        // CANCELLED терминален: прогрессирующее событие не воскрешает заказ
        $order = $this->persistOrder(Status::ORDER_CANCELLED);

        $result = $this->runGuard($order, 'assembled');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status_id', $result);
    }

    public function test_finished_order_is_not_downgraded_by_event(): void
    {
        $order = $this->persistOrder(Status::ORDER_FINISHED);

        $result = $this->runGuard($order, 'in_delivery');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('status_id', $result);
    }

    public function test_transformed_data_without_status_passes_through(): void
    {
        // События без status_id (частичные апдейты) guard не трогает
        $order = $this->persistOrder(Status::ORDER_ASSEMBLED);

        $result = $this->transformer->beforeUpdate($order, ['event_id' => 'sdg-x'], ['amount' => 100.0]);

        $this->assertSame(['amount' => 100.0], $result);
    }
}
