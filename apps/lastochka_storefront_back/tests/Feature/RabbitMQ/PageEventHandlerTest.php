<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Page;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PageEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('pages', config('rabbitmq-events.entities.pages'));
    }

    public function test_page_created_event_creates_page(): void
    {
        $eventData = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'page_1',
            'slug' => 'privacy-policy',
            'title' => 'Политика конфиденциальности',
            'content' => '{"blocks":[{"type":"paragraph","data":{"text":"Текст политики"}}]}',
            'meta_title' => 'Политика',
            'is_published' => true,
            'position' => 1,
        ]);

        $result = $this->consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertDatabaseHas('pages', [
            'source' => 'bagisto',
            'external_id' => 'page_1',
            'slug' => 'privacy-policy',
            'title' => 'Политика конфиденциальности',
            'meta_title' => 'Политика',
            'is_published' => true,
            'position' => 1,
        ]);
    }

    public function test_partial_update_does_not_wipe_content(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'page_2',
            'slug' => 'public-offer',
            'title' => 'Публичная оферта',
            'content' => '{"blocks":[{"type":"paragraph","data":{"text":"Полный текст оферты"}}]}',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        // Partial-событие: ключа content нет вообще — локальное значение не должно затираться.
        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'page_2',
            'slug' => 'public-offer',
            'title' => 'Публичная оферта',
            'is_published' => false,
            'event_timestamp' => now()->toIso8601String(),
        ]);
        unset($updated['content']);
        $this->assertEquals('ack', $this->consumer->processEvent($updated));

        $page = Page::where('external_id', 'page_2')->firstOrFail();
        $this->assertStringContainsString('Полный текст оферты', (string) $page->content);
        $this->assertFalse($page->is_published);
    }

    public function test_page_deleted_event_soft_deletes(): void
    {
        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'page_3',
            'slug' => 'about',
            'title' => 'О компании',
            'event_timestamp' => now()->subMinute()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($created));

        $deleted = $this->buildEventData([
            'event_type' => 'deleted',
            'id' => 'page_3',
            'slug' => 'about--deleted-12',
            'title' => 'О компании',
            'event_timestamp' => now()->toIso8601String(),
        ]);
        $this->assertEquals('ack', $this->consumer->processEvent($deleted));

        $this->assertSoftDeleted('pages', [
            'external_id' => 'page_3',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'page_'.Str::random(5),
            'source' => 'bagisto',
            'slug' => 'page-'.Str::lower(Str::random(5)),
            'title' => 'Тестовая страница',
            'content' => '{"blocks":[{"type":"paragraph","data":{"text":"Текст"}}]}',
            'meta_title' => null,
            'meta_description' => null,
            'is_published' => true,
            'published_at' => now()->toIso8601String(),
            'position' => null,
        ], $overrides);
    }
}
