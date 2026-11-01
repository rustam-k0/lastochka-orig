<?php

namespace Tests\Feature\Transformers;

use App\Models\Client;
use App\Models\Order;
use App\Transformers\OrderEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MID-280 фикс-волна (финальное ревью, Important): порядок выкладки между витриной
 * и admin_back не гарантирован — колонку orders.delivery_price_snapshot создаёт
 * миграция admin_back, а ключ delivery_price_snapshot в событие заказа кладёт
 * витрина. Если delivery_back (общая БД) получит событие раньше миграции
 * admin_back, fromEvent() не должен добавлять атрибут для несуществующей колонки:
 * пакет событий пишет атрибуты через forceFill()->save(), и INSERT/UPDATE на
 * неизвестную колонку иначе падает, а событие уходит в очередь ошибок. Проверка
 * (OrderEventTransformer::hasDeliveryPriceSnapshotColumn()) кэшируется в статике —
 * один запрос к схеме за процесс, тот же приём, что уже сделан в
 * OrderDeliveryPriceResolver::snapshotForShop().
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

    private function event(array $extra = []): array
    {
        return array_merge([
            'id' => 'uuid-dps-guard-' . uniqid(),
            'shop_code' => 'default',
            'status' => 'created',
            'payment_type' => 'card',
            'shipping_method' => 'courier',
            'grand_total' => 1600,
        ], $extra);
    }

    private function existingOrder(): Order
    {
        $clientId = Client::create([
            'phone' => '7' . random_int(1000000000, 9999999999),
            'password' => bcrypt('secret'),
        ])->id;

        $order = new Order();
        $order->forceFill([
            'uuid' => 'dps-guard-' . uniqid(),
            'client_id' => $clientId,
            'status_id' => 1,
            'payment_type_id' => 1,
            'type_id' => 2,
            'amount' => 1600,
            'delivery_price' => 100,
            'slot_start_date' => now(),
            'slot_end_date' => now(),
        ])->save();

        return $order->fresh();
    }

    public function test_from_event_skips_snapshot_when_column_missing(): void
    {
        $this->mockColumnMissing();

        $attributes = (new OrderEventTransformer())->fromEvent($this->event([
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]));

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    public function test_from_event_json_string_also_skipped_when_column_missing(): void
    {
        $this->mockColumnMissing();

        $attributes = (new OrderEventTransformer())->fromEvent($this->event([
            'delivery_price_snapshot' => json_encode(self::SNAPSHOT),
        ]));

        $this->assertArrayNotHasKey('delivery_price_snapshot', $attributes);
    }

    public function test_column_check_is_cached_per_process(): void
    {
        // ->once() в mockColumnMissing() провалит тест, если fromEvent дёрнет схему дважды
        $this->mockColumnMissing();
        $transformer = new OrderEventTransformer();

        $transformer->fromEvent($this->event(['delivery_price_snapshot' => self::SNAPSHOT]));
        $transformer->fromEvent($this->event(['delivery_price_snapshot' => self::SNAPSHOT]));

        $this->assertTrue(true);
    }

    /**
     * Путь UPDATE консьюмера пакета (EventConsumer: fromEvent → beforeUpdate → forceFill()->save()).
     */
    public function test_update_is_processed_without_error_when_column_missing(): void
    {
        $this->mockColumnMissing();

        $order = $this->existingOrder();
        $transformer = new OrderEventTransformer();

        $event = $this->event([
            'id' => $order->uuid,
            'delivery_price_snapshot' => self::SNAPSHOT,
        ]);

        $data = $transformer->beforeUpdate($order, $event, $transformer->fromEvent($event));

        $this->assertIsArray($data, 'beforeUpdate не должен пропускать обновление заказа');
        $this->assertArrayNotHasKey('delivery_price_snapshot', $data);

        $order->withoutEvents(function () use ($order, $data) {
            $order->forceFill($data)->save();
        });

        $this->assertNull($order->fresh()->delivery_price_snapshot);
    }
}
