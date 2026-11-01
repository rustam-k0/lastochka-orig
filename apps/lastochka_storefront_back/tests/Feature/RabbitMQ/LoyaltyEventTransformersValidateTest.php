<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\RabbitMQ\Transformers\BarcodePrefixEventTransformer;
use App\RabbitMQ\Transformers\LoyaltyCardEventTransformer;
use Tests\TestCase;

/**
 * Пиннит контракт validate() ↔ реальный payload входящих событий.
 *
 * EventConsumer::processEvent() (vendor/comrades/laravel-rabbitmq-events)
 * вызывает `$this->transformer->validate($eventData)` ДО роутинга на кастомный
 * handler, где $eventData — это ФЛАТ-структура: результат json_decode() тела
 * AMQP-сообщения целиком (НЕ вложенный `{payload: {...}}`). Если validate()
 * возвращает false — событие молча ACK'ается через InvalidEventException
 * и до handler'а не доходит. Эти тесты гоняют validate() ровно с тем, что
 * реально публикует 1c_parser.
 */
class LoyaltyEventTransformersValidateTest extends TestCase
{
    public function test_loyalty_card_transformer_validates_real_confirmed_payload(): void
    {
        $eventData = [
            'event_id' => '11111111-1111-1111-1111-111111111111',
            'event_type' => 'confirmed',
            'event_source' => '1c_parser',
            'loyalty_id' => 'LC-000123',
            'card_number' => '07123456',
        ];

        $this->assertTrue((new LoyaltyCardEventTransformer())->validate($eventData));
    }

    public function test_loyalty_card_transformer_rejects_payload_without_card_number(): void
    {
        $eventData = [
            'event_id' => '11111111-1111-1111-1111-111111111111',
            'event_type' => 'confirmed',
            'event_source' => '1c_parser',
            'loyalty_id' => 'LC-000123',
        ];

        $this->assertFalse((new LoyaltyCardEventTransformer())->validate($eventData));
    }

    public function test_barcode_prefix_transformer_validates_real_updated_payload(): void
    {
        $eventData = [
            'event_id' => '22222222-2222-2222-2222-222222222222',
            'event_type' => 'updated',
            'event_source' => '1c_parser',
            'type' => 'card',
            'value' => '09',
        ];

        $this->assertTrue((new BarcodePrefixEventTransformer())->validate($eventData));
    }

    public function test_barcode_prefix_transformer_rejects_payload_without_value(): void
    {
        $eventData = [
            'event_id' => '22222222-2222-2222-2222-222222222222',
            'event_type' => 'updated',
            'event_source' => '1c_parser',
            'type' => 'card',
        ];

        $this->assertFalse((new BarcodePrefixEventTransformer())->validate($eventData));
    }
}
