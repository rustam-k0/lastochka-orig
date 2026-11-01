<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Transformers\FaqEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FaqEventTransformerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_to_event_contains_contract_fields(): void
    {
        $faq = Faq::create([
            'question' => 'Как отменить заказ?',
            'answer' => 'Свяжитесь с поддержкой через профиль.',
            'is_active' => true,
            'position' => 3,
        ]);

        $event = (new FaqEventTransformer())->toEvent($faq);

        $this->assertSame((string) $faq->id, $event['id']);
        $this->assertSame('Как отменить заказ?', $event['question']);
        $this->assertSame('Свяжитесь с поддержкой через профиль.', $event['answer']);
        $this->assertTrue($event['is_active']);
        $this->assertSame(3, $event['position']);
        $this->assertNotEmpty($event['event_id']);
        $this->assertArrayHasKey('source', $event);
    }

    public function test_to_event_keeps_null_position(): void
    {
        $faq = Faq::create([
            'question' => 'Вопрос без позиции',
            'answer' => 'Ответ',
        ]);

        $event = (new FaqEventTransformer())->toEvent($faq);

        $this->assertNull($event['position']);
    }

    public function test_faqs_entity_registered_in_config(): void
    {
        $config = config('rabbitmq-events.entities.faqs');

        $this->assertNotNull($config, 'Запись faqs отсутствует в config/rabbitmq-events.php');
        $this->assertTrue($config['enabled']);
        $this->assertSame(\App\Models\Faq::class, $config['model']);
        $this->assertSame(['created', 'updated', 'deleted'], $config['events']);
        $this->assertSame('faq', $config['routing_key_prefix']);
    }
}
