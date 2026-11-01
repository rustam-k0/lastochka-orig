<?php

namespace Tests\Feature\RetailCRM;

use App\Services\Converters\OrderConverter;
use App\Services\RabbitMQ\Publisher;
use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Mockery;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Тест на обработку вебхука с некорректными данными.
     */
    public function test_webhook_with_invalid_data(): void
    {
        $response = $this->postJson('/api/webhooks/retailcrm', [
            // Отсутствует обязательное поле order_id
            'type' => 'order_create',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'Некорректные данные вебхука',
        ]);
    }
    
    /**
     * Тест на успешную обработку вебхука о создании заказа.
     *
     * Вебхук несёт только type и order_id, данные заказа запрашиваются из RetailCRM,
     * событие ставится в outbox (не публикуется в RabbitMQ напрямую).
     */
    public function test_order_create_webhook(): void
    {
        config(['retailcrm.source' => 'retailcrm']);

        // Прямая публикация в RabbitMQ не используется — только outbox
        $publisherMock = Mockery::mock(Publisher::class);
        $publisherMock->shouldNotReceive('publish');

        $this->app->instance(Publisher::class, $publisherMock);

        // Мокаем RetailCRMService
        $retailCrmServiceMock = Mockery::mock(RetailCRMService::class);
        $retailCrmServiceMock->shouldReceive('getOrder')
            ->once()
            ->with(12345)
            ->andReturn((object)[
                'success' => true,
                'order' => (object)[
                    'id' => 12345,
                    'externalId' => 'order-1',
                    'site' => 'default',
                    'status' => 'new',
                ],
            ]);

        $this->app->instance(RetailCRMService::class, $retailCrmServiceMock);

        // Мокаем OrderConverter
        $orderConverterMock = Mockery::mock(OrderConverter::class);
        $orderConverterMock->shouldReceive('fromRetailCRM')
            ->once()
            ->with(Mockery::on(fn ($order) => $order['id'] === 12345), null)
            ->andReturn([
                'id' => 'order-1',
                'externalId' => 'order-1',
                'shop_code' => 'default',
                'source' => 'retailcrm',
                'status' => 'created',
            ]);

        $this->app->instance(OrderConverter::class, $orderConverterMock);

        $response = $this->postJson('/api/webhooks/retailcrm', [
            'type' => 'order_create',
            'order_id' => 12345,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Проверяем, что маппинг был создан
        $this->assertDatabaseHas('order_mappings', [
            'id' => 'order-1',
            'external_id' => '12345',
            'source' => 'retailcrm',
        ]);

        // Проверяем, что событие поставлено в outbox
        $this->assertDatabaseHas('event_outbox', [
            'entity_type' => 'order',
            'entity_id' => 'order-1',
            'event_type' => 'created',
            'routing_key' => 'order.created',
            'status' => 'pending',
        ]);
    }
}
