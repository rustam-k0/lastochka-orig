<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Faq;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FaqEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('faqs', config('rabbitmq-events.entities.faqs'));
    }

    public function test_faq_created_event_creates_faq(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'faq_1',
            'question' => 'Как оформить заказ?',
            'answer' => 'Добавьте товары в корзину и оформите заказ.',
            'is_active' => true,
            'position' => 2,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('faqs', [
            'source' => 'bagisto',
            'external_id' => 'faq_1',
            'question' => 'Как оформить заказ?',
            'answer' => 'Добавьте товары в корзину и оформите заказ.',
            'is_active' => true,
            'position' => 2,
        ]);
    }

    public function test_partial_update_does_not_wipe_answer(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'faq_2',
            'question' => 'Вопрос',
            'answer' => 'Полный ответ',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        // Partial-событие: ключа answer нет вообще — локальное значение не должно затираться.
        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'faq_2',
            'question' => 'Вопрос',
            'is_active' => false,
            'event_timestamp' => now()->toIso8601String(),
        ]);
        unset($updated['answer']);
        $this->assertEquals('ack', $this->consumer->processEvent($updated));

        $faq = Faq::where('external_id', 'faq_2')->firstOrFail();
        $this->assertSame('Полный ответ', $faq->answer);
        $this->assertFalse($faq->is_active);
    }

    public function test_faq_deleted_event_soft_deletes(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'faq_3',
            'question' => 'Удаляемый вопрос',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        $deleted = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'faq_3',
            'question' => 'Удаляемый вопрос',
            'event_timestamp' => now()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($deleted));

        $this->assertSoftDeleted('faqs', [
            'external_id' => 'faq_3',
        ]);
    }

    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'faq_'.Str::random(5),
            'source' => 'bagisto',
            'question' => 'Тестовый вопрос',
            'answer' => 'Тестовый ответ',
            'is_active' => true,
            'position' => null,
        ], $overrides);
    }
}
