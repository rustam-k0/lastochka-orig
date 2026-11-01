<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Address;
use App\Models\Store;
use App\Models\StoreSchedule;
use App\Models\User;
use App\Services\SlotsApi\Contracts\SlotsApiClientInterface;
use App\Services\SlotsApi\FakeSlotsApiClient;
use App\Services\Store\AvailableStoreService;
use Comrades\RabbitMQEvents\Models\EventSyncMetadata;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoreEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('shops', config('rabbitmq-events.entities.shops'));
    }

    public function test_shop_created_event_creates_store(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_001',
            'title' => 'Магазин на Ленина',
            'active' => true,
            'latitude' => 55.7558,
            'longitude' => 37.6173,
            'slot_time' => 120,
            'open_24_7' => false,
            'schedule' => [
                'monday' => ['start' => '09:00', 'end' => '21:00'],
                'tuesday' => ['start' => '09:00', 'end' => '21:00'],
                'sunday' => null,
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('stores', [
            'code' => 'MSK_001',
            'name' => 'Магазин на Ленина',
            'is_active' => true,
            'slot_duration' => 120,
            'is_open_24_7' => false,
        ]);

        $store = Store::where('code', 'MSK_001')->first();
        $this->assertNotNull($store);
        $this->assertEquals(55.7558, (float) $store->latitude);
        $this->assertEquals(37.6173, (float) $store->longitude);

        // Проверяем расписание
        $this->assertDatabaseHas('store_schedules', [
            'store_id' => $store->id,
            'day_of_week' => 1,
            'opens_at' => '09:00',
            'closes_at' => '21:00',
        ]);

        // Проверяем выходной (sunday = null)
        $this->assertDatabaseHas('store_schedules', [
            'store_id' => $store->id,
            'day_of_week' => 7,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }

    public function test_shop_updated_event_updates_store(): void
    {
        $store = Store::factory()->create([
            'code' => 'MSK_002',
            'name' => 'Старое название',
            'is_active' => true,
            'slot_duration' => 60,
        ]);

        // Создаём metadata для связи с внешней системой
        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_2',
            'source' => 'bagisto',
            'shop_code' => 'MSK_002',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'ext_shop_2',
            'shop_code' => 'MSK_002',
            'title' => 'Новое название',
            'active' => false,
            'slot_time' => 90,
            'schedule' => [
                'monday' => ['start' => '10:00', 'end' => '22:00'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $store->refresh();
        $this->assertEquals('Новое название', $store->name);
        $this->assertFalse($store->is_active);
        $this->assertEquals(90, $store->slot_duration);

        // Проверяем что расписание обновилось
        $this->assertDatabaseHas('store_schedules', [
            'store_id' => $store->id,
            'day_of_week' => 1,
            'opens_at' => '10:00',
            'closes_at' => '22:00',
        ]);
    }

    public function test_shop_updated_event_invalidates_available_stores_cache(): void
    {
        $store = Store::factory()->create([
            'code' => 'MSK_CACHE',
            'min_order_amount' => 600,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_cache',
            'source' => 'bagisto',
            'shop_code' => 'MSK_CACHE',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create([
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        /** @var FakeSlotsApiClient $slotsApi */
        $slotsApi = $this->app->make(SlotsApiClientInterface::class);
        $slotsApi->setShops([
            ['id' => 1, 'code' => 'MSK_CACHE', 'delivery_price' => 100, 'min_order_price' => 600],
        ]);

        $available = $this->app->make(AvailableStoreService::class);
        $available->getAvailableStores($address);

        $this->assertTrue(
            Cache::has("available_stores:address:{$address->id}"),
            'Кеш должен быть прогрет перед событием.'
        );

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'ext_shop_cache',
            'shop_code' => 'MSK_CACHE',
            'min_order_price' => 610,
            'min_pickup_order_amount' => 410,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertFalse(
            Cache::has("available_stores:address:{$address->id}"),
            'Кеш должен быть сброшен после shop.updated, иначе клиент видит старый min_order_price.'
        );

        $store->refresh();
        $this->assertEquals(610, (int) $store->min_order_amount);
        $this->assertEquals(410, (int) $store->min_pickup_order_amount);
    }

    public function test_shop_deleted_event_soft_deletes_store(): void
    {
        $store = Store::factory()->create([
            'code' => 'MSK_003',
            'name' => 'Магазин для удаления',
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_3',
            'source' => 'bagisto',
            'shop_code' => 'MSK_003',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'ext_shop_3',
            'shop_code' => 'MSK_003',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Магазин должен быть soft deleted
        $this->assertSoftDeleted('stores', [
            'id' => $store->id,
            'code' => 'MSK_003',
        ]);

        // Но всё ещё доступен через withTrashed
        $this->assertNotNull(Store::withTrashed()->find($store->id));
    }

    public function test_schedule_is_synced(): void
    {
        $store = Store::factory()->create(['code' => 'MSK_004']);

        // Создаём старое расписание
        for ($day = 1; $day <= 3; $day++) {
            StoreSchedule::create([
                'store_id' => $store->id,
                'day_of_week' => $day,
                'opens_at' => '08:00',
                'closes_at' => '20:00',
            ]);
        }

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_4',
            'source' => 'bagisto',
            'shop_code' => 'MSK_004',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'ext_shop_4',
            'shop_code' => 'MSK_004',
            'schedule' => [
                'monday' => ['start' => '09:00', 'end' => '21:00'],
                'friday' => ['start' => '09:00', 'end' => '23:00'],
            ],
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Старое расписание должно быть удалено, новое создано
        $store->refresh();
        $schedules = $store->schedules;

        $this->assertCount(2, $schedules);

        // monday = 1, friday = 5
        $monday = $schedules->firstWhere('day_of_week', 1);
        $friday = $schedules->firstWhere('day_of_week', 5);

        $this->assertNotNull($monday);
        $this->assertNotNull($friday);
        $this->assertEquals('09:00:00', $monday->opens_at->format('H:i:s'));
        $this->assertEquals('23:00:00', $friday->closes_at->format('H:i:s'));
    }

    public function test_duplicate_event_is_skipped(): void
    {
        $eventId = Str::uuid()->toString();

        $eventData = $this->buildEventData([
            'event_id' => $eventId,
            'event_type' => 'created',
            'shop_code' => 'MSK_005',
            'title' => 'Магазин',
        ]);

        // Первый раз — создаём магазин
        $result1 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result1);

        // Второй раз с тем же event_id — должен быть пропущен
        $result2 = $this->consumer->processEvent($eventData);
        $this->assertEquals('ack', $result2);

        // Магазин должен быть только один
        $this->assertDatabaseCount('stores', 1);
    }

    public function test_existing_store_is_linked_by_code(): void
    {
        // Магазин уже существует в базе (создан не через RabbitMQ)
        $existingStore = Store::factory()->create([
            'code' => 'MSK_006',
            'name' => 'Существующий магазин',
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'ext_shop_6',
            'shop_code' => 'MSK_006',
            'title' => 'Обновлённое название',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Магазин должен быть только один (не создан дубликат)
        $this->assertDatabaseCount('stores', 1);

        // Metadata должна быть связана с существующим магазином
        $this->assertDatabaseHas('event_sync_metadata', [
            'entity_type' => 'shops',
            'entity_id' => $existingStore->id,
            'external_id' => 'ext_shop_6',
            'source' => 'bagisto',
        ]);

        // Данные должны быть обновлены
        $existingStore->refresh();
        $this->assertEquals('Обновлённое название', $existingStore->name);
    }

    public function test_outdated_event_is_ignored(): void
    {
        $store = Store::factory()->create([
            'code' => 'MSK_007',
            'name' => 'Актуальное название',
        ]);

        // Metadata с более новым timestamp
        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'ext_shop_7',
            'source' => 'bagisto',
            'shop_code' => 'MSK_007',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now(),
            // Guard устаревших событий сравнивает per-type метку из
            // metadata.event_timestamps[тип], а не last_event_timestamp:
            // при пустом event_timestamps он намеренно пропускает событие
            // (обратная совместимость для записей, созданных до этой правки).
            'metadata' => ['event_timestamps' => ['updated' => now()->toIso8601String()]],
        ]);

        // Событие с более старым timestamp
        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'event_timestamp' => now()->subDay()->toIso8601String(),
            'id' => 'ext_shop_7',
            'shop_code' => 'MSK_007',
            'title' => 'Устаревшее название',
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        // Название не должно измениться
        $store->refresh();
        $this->assertEquals('Актуальное название', $store->name);
    }

    public function test_store_with_24_7_schedule(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'MSK_008',
            'title' => 'Круглосуточный магазин',
            'open_24_7' => true,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);

        $this->assertDatabaseHas('stores', [
            'code' => 'MSK_008',
            'is_open_24_7' => true,
        ]);
    }

    public function test_validation_fails_without_required_fields(): void
    {
        $this->expectException(\Comrades\RabbitMQEvents\Exceptions\InvalidEventException::class);

        // Событие без обязательного поля shop_code
        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_9',
            'source' => 'bagisto',
            // shop_code отсутствует
            'title' => 'Магазин без кода',
        ];

        $this->consumer->processEvent($eventData);
    }

    public function test_shop_event_propagates_ignore_stock_flag(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'IGNORE_STOCK_001',
            'ignore_stock' => true,
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'IGNORE_STOCK_001')->first();
        $this->assertNotNull($store);
        $this->assertTrue($store->ignore_stock);
    }

    public function test_shop_event_defaults_ignore_stock_to_false(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'NO_IGNORE_001',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'NO_IGNORE_001')->first();
        $this->assertNotNull($store);
        $this->assertFalse($store->ignore_stock);
    }

    public function test_shop_event_propagates_restaurant_type(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'RESTAURANT_001',
            'type' => 'restaurant',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'RESTAURANT_001')->first();
        $this->assertNotNull($store);
        $this->assertSame('restaurant', $store->type);
    }

    public function test_shop_event_defaults_type_to_shop_when_missing(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'NO_TYPE_001',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'NO_TYPE_001')->first();
        $this->assertNotNull($store);
        $this->assertSame('shop', $store->type);
    }

    public function test_shop_event_ignores_unknown_type_value(): void
    {
        // Незнакомое значение type (напр. новая опечатка в 1С/админке) не должно
        // пропасть как есть — модель ограничена enum'ом shop|restaurant.
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'UNKNOWN_TYPE_001',
            'type' => 'warehouse',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'UNKNOWN_TYPE_001')->first();
        $this->assertNotNull($store);
        $this->assertSame('shop', $store->type);
    }

    public function test_shop_event_propagates_description(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'DESC_001',
            'description' => 'Продукты, Своя Кухня, Бытовая Химия',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'DESC_001')->first();
        $this->assertNotNull($store);
        $this->assertEquals('Продукты, Своя Кухня, Бытовая Химия', $store->description);
    }

    public function test_shop_event_defaults_description_to_null(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'shop_code' => 'NO_DESC_001',
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store = Store::where('code', 'NO_DESC_001')->first();
        $this->assertNotNull($store);
        $this->assertNull($store->description);
    }

    public function test_retailbridge_zero_coordinates_do_not_overwrite_store_coordinates(): void
    {
        // admin_back публикует магазин с source=retailbridge и latitude=0/longitude=0,
        // когда координаты в курьерке не заданы. Эти нули не должны затирать реальные
        // координаты магазина (ALADDIN-11465), иначе Яндекс-доставка падает с
        // estimating.claim.no_zone_id → 500.
        $store = Store::factory()->create([
            'code' => 'RB_COORD',
            'latitude' => 55.7558,
            'longitude' => 37.6173,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'rb_shop',
            'source' => 'retailbridge',
            'shop_code' => 'RB_COORD',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'rb_shop',
            'source' => 'retailbridge',
            'shop_code' => 'RB_COORD',
            'title' => 'Аладдин',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store->refresh();
        $this->assertEquals(55.7558, (float) $store->latitude, 'Нулевые координаты не должны затирать реальные');
        $this->assertEquals(37.6173, (float) $store->longitude);
        // Прочие поля события при этом применяются.
        $this->assertEquals('Аладдин', $store->name);
    }

    public function test_retailbridge_valid_coordinates_are_applied(): void
    {
        // Ключевой кейс синхронизации: admin_back публикует обновлённые координаты
        // магазина с source=retailbridge (это origin магазина, а не «нет данных»).
        // Валидные (ненулевые) координаты должны применяться — иначе обновление
        // координат в админке курьерки не доезжает до витрины (ALADDIN-11465).
        $store = Store::factory()->create([
            'code' => 'RB_VALID',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'rb_valid',
            'source' => 'retailbridge',
            'shop_code' => 'RB_VALID',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'rb_valid',
            'source' => 'retailbridge',
            'shop_code' => 'RB_VALID',
            'latitude' => 43.259414,
            'longitude' => 46.583699,
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store->refresh();
        $this->assertEquals(43.259414, (float) $store->latitude, 'Валидные координаты от admin_back (source=retailbridge) должны применяться');
        $this->assertEquals(46.583699, (float) $store->longitude);
    }

    public function test_zero_coordinates_do_not_overwrite_existing(): void
    {
        // Даже от «нормального» источника нулевые координаты — это «нет данных»,
        // партиальное событие не должно обнулять координаты магазина.
        $store = Store::factory()->create([
            'code' => 'APP_ZERO',
            'latitude' => 55.0,
            'longitude' => 37.0,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'app_zero',
            'source' => 'app',
            'shop_code' => 'APP_ZERO',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'app_zero',
            'source' => 'app',
            'shop_code' => 'APP_ZERO',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store->refresh();
        $this->assertEquals(55.0, (float) $store->latitude);
        $this->assertEquals(37.0, (float) $store->longitude);
    }

    public function test_valid_coordinates_from_app_source_are_updated(): void
    {
        // Валидные координаты от админки (source != retailbridge) применяются.
        $store = Store::factory()->create([
            'code' => 'APP_UPD',
            'latitude' => 10.0,
            'longitude' => 10.0,
        ]);

        EventSyncMetadata::create([
            'entity_type' => 'shops',
            'entity_id' => $store->id,
            'external_id' => 'app_upd',
            'source' => 'app',
            'shop_code' => 'APP_UPD',
            'last_event_id' => Str::uuid()->toString(),
            'last_event_timestamp' => now()->subHour(),
        ]);

        $eventData = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'app_upd',
            'source' => 'app',
            'shop_code' => 'APP_UPD',
            'latitude' => 43.2545650,
            'longitude' => 46.5878430,
        ]);

        $this->assertEquals('ack', $this->consumer->processEvent($eventData));

        $store->refresh();
        $this->assertEquals(43.2545650, (float) $store->latitude);
        $this->assertEquals(46.5878430, (float) $store->longitude);
    }

    /**
     * Построить данные события с дефолтными значениями.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'ext_shop_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'title' => 'Тестовый магазин',
            'active' => true,
        ], $overrides);
    }
}
