<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\RabbitMQ\Transformers\BonusOperationEventTransformer;
use Tests\TestCase;

/**
 * Пиннит контракт validate() ↔ реальный payload собственного эха.
 *
 * Storefront публикует bonus_operation.created и сам же на него подписан.
 * EventConsumer::processEvent() (vendor/comrades/laravel-rabbitmq-events)
 * вызывает $this->transformer->validate($eventData) ДО отсечения по
 * event_source, поэтому обязательный loyalty_id валил бы каждую операцию
 * для клиента без карты лояльности в ERROR-лог ещё до идемпотентной
 * проверки по operation_id (фикс: loyalty_id убран из requiredFields).
 */
class BonusOperationEventTransformerValidateTest extends TestCase
{
    public function test_validates_own_echo_payload_without_loyalty_id(): void
    {
        $eventData = [
            'event_id' => '33333333-3333-3333-3333-333333333333',
            'event_source' => config('rabbitmq-events.source'),
            'operation_id' => 'ADM-OP-1',
            'loyalty_id' => null,
            'type' => 'earn',
            'amount' => 100,
        ];

        $this->assertTrue((new BonusOperationEventTransformer())->validate($eventData));
    }

    public function test_rejects_payload_without_operation_id(): void
    {
        $eventData = [
            'event_id' => '33333333-3333-3333-3333-333333333333',
            'event_source' => config('rabbitmq-events.source'),
            'loyalty_id' => null,
            'type' => 'earn',
            'amount' => 100,
        ];

        $this->assertFalse((new BonusOperationEventTransformer())->validate($eventData));
    }
}
