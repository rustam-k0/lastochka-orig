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
 * Товар со «своим» source (retailbridge), но id не из RetailCRM.
 *
 * Bagisto публикует product.* с source=retailbridge и id = id Bagisto (19),
 * а getOrCreateMetadata считал id локальным и писал entity_id = 19. В RetailCRM
 * товара 19 нет (у товара с externalId 19 id 9137), правки уходили в никуда
 * или в чужой товар. Инцидент foodlink 16.09.2026: 646 таких маппингов.
 */
class ProductLocalSourceMappingTest extends TestCase
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
        $this->hashServiceMock = Mockery::mock(EntityHashService::class);
        $this->hashServiceMock->shouldReceive('saveHashAfterSync')->andReturn(true)->byDefault();
        $this->hashServiceMock->shouldReceive('hasChanged')->andReturn(true)->byDefault();

        $this->converterMock->shouldReceive('toRetailCRM')->andReturn([
            'name' => 'GOLF Диффузор',
            'article' => '0004',
            'externalId' => '19',
            'price' => 661,
        ])->byDefault();

        $this->retailCrmServiceMock->shouldReceive('uploadPrices')->andReturn((object) ['processedOffersCount' => 1])->byDefault();
        $this->retailCrmServiceMock->shouldReceive('uploadInventories')->andReturn((object) ['processedOffersCount' => 1])->byDefault();

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

    protected function message(string $routingKey = 'product.updated'): AMQPMessage
    {
        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getRoutingKey')->andReturn($routingKey);

        return $message;
    }

    protected function event(string $id, string $eventId): array
    {
        return [
            'id' => $id,
            'source' => 'retailbridge',
            'event_source' => 'bagisto',
            'event_id' => $eventId,
            'timestamp' => time(),
            'shop_code' => 'dag_bars',
            'title' => 'GOLF Диффузор',
            'sku' => '0004',
            'price' => 661,
        ];
    }

    public function test_bagisto_id_with_own_source_is_resolved_by_external_id(): void
    {
        $this->retailCrmServiceMock->shouldReceive('getProducts')
            ->with(['ids' => [19]], 1, 20)->once()
            ->andReturn((object) ['products' => []]);

        $this->retailCrmServiceMock->shouldReceive('getProducts')
            ->with(['externalId' => '19'], 1, 20)->once()
            ->andReturn((object) ['products' => [(object) ['id' => 9137, 'externalId' => '19', 'article' => '0004', 'imageUrl' => null]]]);

        $this->retailCrmServiceMock->shouldReceive('updateProduct')
            ->once()->with('9137', Mockery::any(), 'id')->andReturn(9137);

        $this->assertTrue($this->handler->handle($this->event('19', 'evt-1'), $this->message()));

        $metadata = EventSyncMetadata::where('entity_type', 'product')->where('source', 'retailbridge')->where('external_id', '19')->first();

        $this->assertNotNull($metadata);
        $this->assertSame('9137', (string) $metadata->entity_id);
        $this->assertSame(0, EventSyncMetadata::where('entity_type', 'product')->where('entity_id', '19')->count());
    }

    public function test_genuine_retailcrm_id_is_verified_once_and_used_as_local_entity(): void
    {
        $this->retailCrmServiceMock->shouldReceive('getProducts')
            ->with(['ids' => [9137]], 1, 20)->once()
            ->andReturn((object) ['products' => [(object) ['id' => 9137, 'externalId' => '19', 'article' => '0004', 'imageUrl' => null]]]);

        $this->retailCrmServiceMock->shouldReceive('getProduct')->andReturn(null);

        $this->retailCrmServiceMock->shouldReceive('updateProduct')
            ->twice()->with('9137', Mockery::any(), 'id')->andReturn(9137);

        $this->assertTrue($this->handler->handle($this->event('9137', 'evt-1'), $this->message()));
        $this->assertTrue($this->handler->handle(array_merge($this->event('9137', 'evt-2'), ['timestamp' => time() + 5]), $this->message()));

        $metadata = EventSyncMetadata::where('entity_type', 'product')->where('entity_id', '9137')->first();

        $this->assertNotNull($metadata);
        $this->assertTrue((bool) ($metadata->data['local_entity_verified'] ?? false));
    }

    public function test_corrupted_mapping_with_bagisto_id_is_healed(): void
    {
        EventSyncMetadata::create([
            'entity_type' => 'product',
            'source' => 'retailbridge',
            'external_id' => '19',
            'shop_code' => 'dag_bars',
            'entity_id' => '19',
        ]);

        $this->retailCrmServiceMock->shouldReceive('getProducts')
            ->with(['ids' => [19]], 1, 20)->once()
            ->andReturn((object) ['products' => []]);

        $this->retailCrmServiceMock->shouldReceive('getProducts')
            ->with(['externalId' => '19'], 1, 20)->once()
            ->andReturn((object) ['products' => [(object) ['id' => 9137, 'externalId' => '19', 'article' => '0004', 'imageUrl' => null]]]);

        $this->retailCrmServiceMock->shouldReceive('updateProduct')
            ->once()->with('9137', Mockery::any(), 'id')->andReturn(9137);

        $this->assertTrue($this->handler->handle($this->event('19', 'evt-1'), $this->message()));

        $this->assertSame(1, EventSyncMetadata::where('entity_type', 'product')->where('external_id', '19')->count());
        $this->assertSame('9137', (string) EventSyncMetadata::where('entity_type', 'product')->where('external_id', '19')->value('entity_id'));
    }

    public function test_retailcrm_error_during_verification_keeps_mapping_untouched(): void
    {
        EventSyncMetadata::create([
            'entity_type' => 'product',
            'source' => 'retailbridge',
            'external_id' => '19',
            'shop_code' => 'dag_bars',
            'entity_id' => '19',
        ]);

        $this->retailCrmServiceMock->shouldReceive('getProducts')
            ->with(['ids' => [19]], 1, 20)->once()
            ->andReturn(false);

        $this->retailCrmServiceMock->shouldNotReceive('updateProduct');
        $this->retailCrmServiceMock->shouldNotReceive('createProduct');

        $this->assertFalse($this->handler->handle($this->event('19', 'evt-1'), $this->message()));

        $this->assertSame('19', (string) EventSyncMetadata::where('entity_type', 'product')->where('external_id', '19')->value('entity_id'));
    }
}
