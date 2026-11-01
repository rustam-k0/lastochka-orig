<?php

namespace Tests\Feature\DeliveryPriceRanges;

use App\Models\Client;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MID-280 фикс-волна (финальное ревью, Important): порядок выкладки не гарантирован —
 * колонку orders.delivery_price_snapshot создаёт миграция admin_back, а ключ
 * delivery_price_snapshot в событие заказа кладёт витрина. Если форк раскатает
 * витрину раньше миграции admin_back, fromEvent() не должен добавлять атрибут для
 * несуществующей колонки: пакет событий пишет атрибуты через forceFill()->save(),
 * и INSERT/UPDATE на неизвестную колонку иначе падает, а событие уходит в очередь
 * ошибок. Проверка (OrderEventTransformer::hasDeliveryPriceSnapshotColumn())
 * кэшируется в статике — один запрос к схеме за процесс, тот же приём, что в
 * delivery_back OrderDeliveryPriceResolver::snapshotForShop().
 *
 * Отсутствие колонки имитируется подменой Schema::hasColumn() (Facade::partialMock),
 * без удаления колонки в общей БД courier_test — тест не оставляет мусора.
 */
class OrderEventDeliveryPriceSnapshotColumnGuardTest extends TestCase
{
    use DatabaseTransactions;

    private const SNAPSHOT = [
        'base_price' => 200,
        'ranges' => [
            ['from_amount' => 1000, 'price' => 100],
        ],
        'checkout_price' => 100,
    ];

    protected function tearDown(): void
    {
        OrderEventTransformer::resetDeliveryPriceSnapshotColumnCache();
        parent::tearDown();
    }

    /**
     * Подмена Schema::hasColumn() для конкретной пары (таблица, колонка): остальные
     * вызовы Schema идут в реальную реализацию (Facade::partialMock). ->once()
     * заодно доказывает кэширование — второй вызов hasDeliveryPriceSnapshotColumn()
     * в рамках теста не должен дёрнуть схему повторно.
     */
    private function mockColumnMissing(): void
    {
        Schema::partialMock()
            ->shouldReceive('hasColumn')
            ->once()
            ->with('orders', 'delivery_price_snapshot')
            ->andReturn(false);

        OrderEventTransformer::resetDeliveryPriceSnapshotColumnCache();
    }

    private function eventPayload(array $extra = []): array
    {
        return array_merge([
            'id' => 'mid280-guard-' . uniqid(),
            'shop_code' => 'default',
            'event_type' => 'created',
            'status' => 'created',
            'payment_type' => 'cash',
            'shipping_method' => 'courier',
            'grand_total' => 1600,
        ], $extra);
    }

    private function client(): Client
    {
        return Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'is_authorized' => true,
        ]);
    }

    private function order(array $extra = []): Order
    {
        // Клиент создаётся до withoutEvents: иначе не сработает его хук creating (пароль)
        $clientId = $this->client()->id;

        return Order::withoutEvents(fn () => Order::create(array_merge([
            'uuid' => 'mid280-guard-order-' . uniqid(),
            'client_id' => $clientId,
            'shop_id' => Shop::firstOrFail()->id,
            'type_id' => Type::DELIVERY_COURIER,
            'status_id' => Status::ORDER_CREATED,
            'payment_type_id' => Type::PAYMENT_CASH,
            'amount' => 1600,
            'origin_amount' => 1500,
            'paid' => false,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
            'delivery_price' => 100,
        ], $extra)));
    }

    public function test_from_event_skips_snapshot_when_column_missing(): void
    {
        $this->mockColumnMissing();

        $attributes = (new OrderEventTransformer())->fromEvent($this->eventPayload([
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    public function test_from_event_json_string_also_skipped_when_column_missing(): void
    {
        $this->mockColumnMissing();

        $attributes = (new OrderEventTransformer())->fromEvent($this->eventPayload([
            'delivery_price_snapshot' => json_encode(self::SNAPSHOT),
        ]));

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    public function test_column_check_is_cached_per_process(): void
    {
        // ->once() в mockColumnMissing() провалит тест, если fromEvent дёрнет схему дважды
        $this->mockColumnMissing();
        $transformer = new OrderEventTransformer();

        $transformer->fromEvent($this->eventPayload(['delivery_price_snapshot' => self::SNAPSHOT]));
        $transformer->fromEvent($this->eventPayload(['delivery_price_snapshot' => self::SNAPSHOT]));

        $this->assertTrue(true);
    }

    public function test_update_is_processed_without_error_when_column_missing(): void
    {
        $this->mockColumnMissing();

        $order = $this->order();
        $transformer = new OrderEventTransformer();

        $event = $this->eventPayload([
            'id' => $order->uuid,
            'event_type' => 'updated',
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]);

        $transformed = $transformer->beforeUpdate($order, ['event_type' => 'updated'], $transformer->fromEvent($event));

        $this->assertIsArray($transformed, 'beforeUpdate не должен пропускать обновление заказа');
        $this->assertArrayNotHasKey('delivery_price_snapshot', $transformed);

        // Путь пакета событий: forceFill()->save() — без ошибки о неизвестной колонке
        $order->forceFill($transformed)->save();

        $this->assertNull($order->fresh()->delivery_price_snapshot);
    }
}
