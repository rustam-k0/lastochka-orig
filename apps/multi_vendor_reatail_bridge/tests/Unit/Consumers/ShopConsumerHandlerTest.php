<?php

namespace Tests\Unit\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Consumers\ShopConsumerHandler;
use App\Services\Converters\ShopConverter;
use App\Services\Hash\EntityHashService;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

/**
 * Тесты ShopConsumerHandler.
 * 
 * @see docs/tz/002-shops-exchange.md
 */
class ShopConsumerHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected ShopConsumerHandler $handler;
    protected MockInterface $retailCrmServiceMock;
    protected MockInterface $hashServiceMock;
    protected ShopConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rabbitmq-events.source' => 'retailbridge']);
        config(['retailcrm.source' => 'retailcrm']);
        config(['retailcrm.default_country_iso' => 'KZ']);

        $this->converter = new ShopConverter();
        $this->retailCrmServiceMock = Mockery::mock(RetailCRMService::class);

        // Хеш после синхронизации делает GET в RetailCRM — в unit-тестах подменяем сервис
        $this->hashServiceMock = Mockery::mock(EntityHashService::class);
        $this->hashServiceMock
            ->shouldReceive('saveHashAfterSync')
            ->andReturn(true)
            ->byDefault();

        $this->handler = new ShopConsumerHandler(
            $this->converter,
            $this->retailCrmServiceMock,
            $this->hashServiceMock
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Создает mock AMQPMessage с заданным routing key.
     */
    protected function createMockMessage(string $routingKey = 'shop.created'): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getRoutingKey')->andReturn($routingKey);
        return $message;
    }

    // ==================== Happy Path ====================

    /**
     * Тест: успешная обработка события shop.created.
     */
    public function test_handle_created_event_successfully(): void
    {
        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'source' => 'bagisto',
            'event_id' => 'evt-001',
            'event_source' => 'bagisto',
            'timestamp' => time(),
            'title' => 'Магазин Алматы',
            'active' => true,
        ];

        // Мокаем успешный вызов API
        $this->retailCrmServiceMock
            ->shouldReceive('editSite')
            ->once()
            ->andReturn(true);

        $message = $this->createMockMessage('shop.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);

        // Проверяем что метаданные созданы
        $metadata = EventSyncMetadata::where('entity_type', 'shop')
            ->where('external_id', 'shop-almaty-001')
            ->first();

        $this->assertNotNull($metadata);
        $this->assertEquals('shop-almaty', $metadata->entity_id);
        $this->assertEquals('bagisto', $metadata->source);
    }

    /**
     * Тест: успешная обработка события shop.updated.
     */
    public function test_handle_updated_event_successfully(): void
    {
        // Создаем существующие метаданные
        EventSyncMetadata::create([
            'entity_type' => 'shop',
            'source' => 'bagisto',
            'external_id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'entity_id' => 'shop-almaty',
        ]);

        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'source' => 'bagisto',
            'event_id' => 'evt-002',
            'event_source' => 'bagisto',
            'timestamp' => time(),
            'title' => 'Магазин Алматы (обновлено)',
            'active' => true,
        ];

        $this->retailCrmServiceMock
            ->shouldReceive('editSite')
            ->once()
            ->andReturn(true);

        $message = $this->createMockMessage('shop.updated');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);
    }

    // ==================== shop.deleted ====================

    /**
     * Тест: shop.deleted помечает site в RetailCRM суффиксом "(УДАЛЁН)".
     */
    public function test_handle_deleted_event_marks_site_as_deleted(): void
    {
        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'source' => 'bagisto',
            'event_id' => 'evt-003',
            'event_source' => 'bagisto',
            'timestamp' => time(),
            'title' => 'Магазин Алматы',
        ];

        $this->retailCrmServiceMock
            ->shouldReceive('editSite')
            ->once()
            ->with(Mockery::on(function ($arg) {
                return $arg['code'] === 'shop-almaty'
                    && $arg['name'] === 'Магазин Алматы (УДАЛЁН)';
            }))
            ->andReturn(true);

        $message = $this->createMockMessage('shop.deleted');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);
    }

    /**
     * Тест: shop.deleted без shop_code — ACK без вызова API.
     */
    public function test_handle_deleted_event_without_shop_code(): void
    {
        $data = [
            'id' => 'shop-almaty-001',
            'source' => 'bagisto',
            'event_id' => 'evt-004',
            'event_source' => 'bagisto',
            'timestamp' => time(),
        ];

        $this->retailCrmServiceMock
            ->shouldNotReceive('editSite');

        $message = $this->createMockMessage('shop.deleted');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);
    }

    // ==================== Loopback Prevention ====================

    /**
     * Тест: пропуск loopback события (event_source === retailbridge).
     */
    public function test_skip_loopback_event(): void
    {
        $data = [
            'id' => 'shop-001',
            'shop_code' => 'shop-test',
            'source' => 'retailbridge',
            'event_id' => 'evt-loop',
            'event_source' => 'retailbridge', // Loopback!
            'timestamp' => time(),
            'title' => 'Test',
        ];

        // API НЕ должен вызываться
        $this->retailCrmServiceMock
            ->shouldNotReceive('editSite');

        $message = $this->createMockMessage('shop.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);
    }

    // ==================== Validation ====================

    /**
     * Тест: валидация - shop_code обязателен.
     */
    public function test_validation_requires_shop_code(): void
    {
        $data = [
            'id' => 'shop-001',
            // shop_code отсутствует!
            'source' => 'bagisto',
            'event_id' => 'evt-001',
            'event_source' => 'bagisto',
            'timestamp' => time(),
            'title' => 'Test',
        ];

        // API НЕ должен вызываться
        $this->retailCrmServiceMock
            ->shouldNotReceive('editSite');

        $message = $this->createMockMessage('shop.created');
        $result = $this->handler->handle($data, $message);

        // Должен вернуть true (ACK) чтобы не ретраить невалидные данные
        $this->assertTrue($result);
    }

    /**
     * Тест: валидация - id обязателен.
     */
    public function test_validation_requires_id(): void
    {
        $data = [
            // id отсутствует!
            'shop_code' => 'shop-test',
            'source' => 'bagisto',
            'event_id' => 'evt-001',
            'event_source' => 'bagisto',
            'timestamp' => time(),
        ];

        $this->retailCrmServiceMock
            ->shouldNotReceive('editSite');

        $message = $this->createMockMessage('shop.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);
    }

    // ==================== Extended Data ====================

    /**
     * Тест: расширенные данные сохраняются в metadata.data.
     */
    public function test_extended_data_saved_in_metadata(): void
    {
        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'source' => 'bagisto',
            'event_id' => 'evt-001',
            'event_source' => 'bagisto',
            'timestamp' => time(),
            'title' => 'Магазин Алматы',
            'schedule' => [
                'monday' => ['start' => '09:00', 'end' => '21:00'],
            ],
            'latitude' => 43.238949,
            'longitude' => 76.945465,
            'slot_time' => 60,
        ];

        $this->retailCrmServiceMock
            ->shouldReceive('editSite')
            ->once()
            ->andReturn(true);

        $message = $this->createMockMessage('shop.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);

        // Проверяем что расширенные данные сохранены
        $metadata = EventSyncMetadata::where('entity_type', 'shop')
            ->where('external_id', 'shop-almaty-001')
            ->first();

        $this->assertNotNull($metadata);
        $this->assertNotNull($metadata->data);
        $this->assertEquals(43.238949, $metadata->data['latitude']);
        $this->assertEquals(76.945465, $metadata->data['longitude']);
        $this->assertEquals(60, $metadata->data['slot_time']);
    }

    // ==================== Error Handling ====================

    /**
     * Тест: ошибка API возвращает false (NACK для retry).
     */
    public function test_api_error_returns_false_for_retry(): void
    {
        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'source' => 'bagisto',
            'event_id' => 'evt-001',
            'event_source' => 'bagisto',
            'timestamp' => time(),
            'title' => 'Магазин Алматы',
        ];

        // API возвращает false (ошибка)
        $this->retailCrmServiceMock
            ->shouldReceive('editSite')
            ->once()
            ->andReturn(false);

        $message = $this->createMockMessage('shop.created');
        $result = $this->handler->handle($data, $message);

        // Должен вернуть false для retry
        $this->assertFalse($result);
    }

    // ==================== Idempotency ====================

    /**
     * Тест: повторное событие с тем же event_id пропускается.
     */
    public function test_duplicate_event_is_skipped(): void
    {
        $eventId = 'evt-duplicate-001';
        $timestamp = time();

        // Создаем метаданные с уже обработанным событием
        EventSyncMetadata::create([
            'entity_type' => 'shop',
            'source' => 'bagisto',
            'external_id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'entity_id' => 'shop-almaty',
            'last_event_id' => $eventId,
            'last_event_timestamp' => now(),
        ]);

        $data = [
            'id' => 'shop-almaty-001',
            'shop_code' => 'shop-almaty',
            'source' => 'bagisto',
            'event_id' => $eventId, // Тот же event_id!
            'event_source' => 'bagisto',
            'timestamp' => $timestamp,
            'title' => 'Магазин Алматы',
        ];

        // API НЕ должен вызываться
        $this->retailCrmServiceMock
            ->shouldNotReceive('editSite');

        $message = $this->createMockMessage('shop.updated');
        $result = $this->handler->handle($data, $message);

        // Должен вернуть true (успешно пропустили дубликат)
        $this->assertTrue($result);
    }
}
