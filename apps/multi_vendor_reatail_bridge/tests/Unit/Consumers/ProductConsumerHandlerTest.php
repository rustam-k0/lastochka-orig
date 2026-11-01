<?php

namespace Tests\Unit\Consumers;

use App\Models\System\EventSyncMetadata;
use App\Services\Consumers\ProductConsumerHandler;
use App\Services\Converters\ProductConverter;
use App\Services\Hash\EntityHashService;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

/**
 * Тесты ProductConsumerHandler.
 * 
 * @see docs/TZ-multi-vendor-bridge-sku-deduplication.md
 */
class ProductConsumerHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected ProductConsumerHandler $handler;
    protected MockInterface $retailCrmServiceMock;
    protected MockInterface $converterMock;
    protected MockInterface $hashServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rabbitmq-events.source' => 'retailbridge']);
        config(['retailcrm.source' => 'retailcrm']);
        config(['retailcrm.shop_code' => 'default']);
        config(['retailcrm.warehouse_code' => 'main']);

        $this->converterMock = Mockery::mock(ProductConverter::class);
        $this->retailCrmServiceMock = Mockery::mock(RetailCRMService::class);

        // Хеш после синхронизации делает GET в RetailCRM — в unit-тестах подменяем сервис
        $this->hashServiceMock = Mockery::mock(EntityHashService::class);
        $this->hashServiceMock
            ->shouldReceive('saveHashAfterSync')
            ->andReturn(true)
            ->byDefault();

        $this->handler = new ProductConsumerHandler(
            $this->converterMock,
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
    protected function createMockMessage(string $routingKey = 'product.created'): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getRoutingKey')->andReturn($routingKey);
        return $message;
    }

    // ==================== Дедупликация по артикулу ====================

    /**
     * Тест: товар находится по артикулу, когда externalId не совпадает.
     * 
     * Сценарий:
     * 1. Товар с SKU-001 уже существует в RetailCRM (создан из 1c)
     * 2. Приходит тот же товар из app с другим external_id, но тем же артикулом
     * 3. Ожидаем: товар найден по артикулу, обновлён, дубль НЕ создан
     */
    public function test_finds_product_by_article_when_external_id_not_found(): void
    {
        $existingRetailCrmProduct = (object)[
            'id' => 100,
            'article' => 'SKU-001',
            'name' => 'Existing Product',
            'externalId' => 'uuid-from-1c',
            'imageUrl' => null,
        ];

        // Поиск по externalId - НЕ находит (пустой результат)
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['externalId' => 'id-from-app'], 1, 20)
            ->once()
            ->andReturn((object)['products' => []]);

        // Поиск по артикулу (через filter[name]) - НАХОДИТ
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['name' => 'SKU-001'], 1, 20)
            ->once()
            ->andReturn((object)['products' => [$existingRetailCrmProduct]]);

        // Конвертер возвращает данные
        $this->converterMock
            ->shouldReceive('toRetailCRM')
            ->once()
            ->andReturn([
                'name' => 'Test Product',
                'article' => 'SKU-001',
                'externalId' => 'id-from-app',
                'price' => 150,
            ]);

        // Ожидаем ОБНОВЛЕНИЕ (не создание!)
        $this->retailCrmServiceMock
            ->shouldReceive('updateProduct')
            ->once()
            ->with('100', Mockery::any(), 'id')
            ->andReturn(100);

        // Мокаем обновление цен/остатков (они вызываются после update)
        $this->retailCrmServiceMock
            ->shouldReceive('uploadPrices')
            ->andReturn((object)['processedOffersCount' => 1]);

        $data = [
            'id' => 'id-from-app',
            'source' => 'app',
            'event_id' => 'evt-001',
            'event_source' => 'app',
            'timestamp' => time(),
            'title' => 'Test Product',
            'sku' => 'SKU-001',
            'price' => 150,
        ];

        $message = $this->createMockMessage('product.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);

        // Проверяем что метаданные связаны с существующим товаром
        $metadata = EventSyncMetadata::where('entity_type', 'product')
            ->where('external_id', 'id-from-app')
            ->first();

        $this->assertNotNull($metadata);
        $this->assertEquals('100', $metadata->entity_id); // ID из RetailCRM
    }

    /**
     * Тест: товар создаётся когда не найден ни по externalId, ни по артикулу.
     */
    public function test_creates_product_when_not_found_by_external_id_or_article(): void
    {
        // Поиск по externalId - НЕ находит
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['externalId' => 'new-product-id'], 1, 20)
            ->once()
            ->andReturn((object)['products' => []]);

        // Поиск по артикулу (через filter[name]) - тоже НЕ находит
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['name' => 'NEW-SKU'], 1, 20)
            ->once()
            ->andReturn((object)['products' => []]);

        // Конвертер возвращает данные
        $this->converterMock
            ->shouldReceive('toRetailCRM')
            ->once()
            ->andReturn([
                'name' => 'New Product',
                'article' => 'NEW-SKU',
                'externalId' => 'new-product-id',
                'price' => 200,
            ]);

        // Ожидаем СОЗДАНИЕ нового товара
        $this->retailCrmServiceMock
            ->shouldReceive('createProduct')
            ->once()
            ->andReturn(201);

        // Мокаем обновление цен/остатков
        $this->retailCrmServiceMock
            ->shouldReceive('uploadPrices')
            ->andReturn((object)['processedOffersCount' => 1]);

        $this->retailCrmServiceMock
            ->shouldReceive('uploadInventories')
            ->andReturn((object)['processedOffersCount' => 1]);

        $data = [
            'id' => 'new-product-id',
            'source' => 'app',
            'event_id' => 'evt-002',
            'event_source' => 'app',
            'timestamp' => time(),
            'title' => 'New Product',
            'sku' => 'NEW-SKU',
            'price' => 200,
            'quantity' => 10,
        ];

        $message = $this->createMockMessage('product.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);

        // Проверяем что метаданные созданы с новым entity_id
        $metadata = EventSyncMetadata::where('entity_type', 'product')
            ->where('external_id', 'new-product-id')
            ->first();

        $this->assertNotNull($metadata);
        $this->assertEquals('201', $metadata->entity_id);
    }

    /**
     * Тест: товар находится по externalId (быстрый путь, артикул не проверяется).
     */
    public function test_finds_product_by_external_id_without_checking_article(): void
    {
        $existingProduct = (object)[
            'id' => 50,
            'article' => 'SKU-EXIST',
            'name' => 'Existing',
            'externalId' => 'existing-ext-id',
            'imageUrl' => null,
        ];

        // Поиск по externalId - НАХОДИТ сразу
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['externalId' => 'existing-ext-id'], 1, 20)
            ->once()
            ->andReturn((object)['products' => [$existingProduct]]);

        // Поиск по артикулу НЕ должен вызываться!
        $this->retailCrmServiceMock
            ->shouldNotReceive('getProducts')
            ->with(['article' => Mockery::any()], Mockery::any(), Mockery::any());

        $this->converterMock
            ->shouldReceive('toRetailCRM')
            ->once()
            ->andReturn([
                'name' => 'Updated Product',
                'article' => 'SKU-EXIST',
                'externalId' => 'existing-ext-id',
                'price' => 300,
            ]);

        $this->retailCrmServiceMock
            ->shouldReceive('updateProduct')
            ->once()
            ->andReturn(50);

        $this->retailCrmServiceMock
            ->shouldReceive('uploadPrices')
            ->andReturn((object)['processedOffersCount' => 1]);

        $data = [
            'id' => 'existing-ext-id',
            'source' => '1c',
            'event_id' => 'evt-003',
            'event_source' => '1c',
            'timestamp' => time(),
            'title' => 'Updated Product',
            'sku' => 'SKU-EXIST',
            'price' => 300,
        ];

        $message = $this->createMockMessage('product.updated');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);
    }

    /**
     * Тест: дедупликация работает с полем article вместо sku.
     */
    public function test_deduplication_works_with_article_field(): void
    {
        $existingProduct = (object)[
            'id' => 75,
            'article' => 'ART-123',
            'name' => 'Product by Article',
            'externalId' => 'old-ext-id',
            'imageUrl' => null,
        ];

        // Поиск по externalId - НЕ находит
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['externalId' => 'new-ext-id'], 1, 20)
            ->once()
            ->andReturn((object)['products' => []]);

        // Поиск по артикулу (через filter[name]) - НАХОДИТ
        $this->retailCrmServiceMock
            ->shouldReceive('getProducts')
            ->with(['name' => 'ART-123'], 1, 20)
            ->once()
            ->andReturn((object)['products' => [$existingProduct]]);

        $this->converterMock
            ->shouldReceive('toRetailCRM')
            ->once()
            ->andReturn([
                'name' => 'Product',
                'article' => 'ART-123',
                'externalId' => 'new-ext-id',
            ]);

        $this->retailCrmServiceMock
            ->shouldReceive('updateProduct')
            ->once()
            ->andReturn(75);

        $this->retailCrmServiceMock
            ->shouldReceive('uploadPrices')
            ->andReturn((object)['processedOffersCount' => 1]);

        $data = [
            'id' => 'new-ext-id',
            'source' => 'app',
            'event_id' => 'evt-004',
            'event_source' => 'app',
            'timestamp' => time(),
            'title' => 'Product',
            'article' => 'ART-123', // Используем article вместо sku
        ];

        $message = $this->createMockMessage('product.created');
        $result = $this->handler->handle($data, $message);

        $this->assertTrue($result);

        $metadata = EventSyncMetadata::where('entity_type', 'product')
            ->where('external_id', 'new-ext-id')
            ->first();

        $this->assertNotNull($metadata);
        $this->assertEquals('75', $metadata->entity_id);
    }
}
