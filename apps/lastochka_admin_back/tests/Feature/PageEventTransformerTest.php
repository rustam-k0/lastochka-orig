<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Transformers\PageEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PageEventTransformerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_event_payload_contains_page_fields(): void
    {
        $page = Page::factory()->published()->create([
            'slug' => 'privacy-' . uniqid(),
            'title' => 'Политика конфиденциальности',
            'meta_title' => 'Политика',
            'meta_description' => 'Как мы обрабатываем данные',
            'position' => 3,
        ]);

        $payload = (new PageEventTransformer())->toEvent($page);

        $this->assertSame((string) $page->id, $payload['id']);
        $this->assertSame($page->slug, $payload['slug']);
        $this->assertSame('Политика конфиденциальности', $payload['title']);
        $this->assertSame($page->content, $payload['content']);
        $this->assertSame('Политика', $payload['meta_title']);
        $this->assertSame('Как мы обрабатываем данные', $payload['meta_description']);
        $this->assertSame(3, $payload['position']);
        $this->assertTrue($payload['is_published']);
        $this->assertNotNull($payload['published_at']);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertSame(config('rabbitmq-events.source'), $payload['source']);

        // Служебный флаг витрине не нужен и наружу не уходит
        $this->assertArrayNotHasKey('is_system', $payload);
    }

    public function test_pages_entity_is_registered_for_publishing(): void
    {
        $entity = config('rabbitmq-events.entities.pages');

        $this->assertNotNull($entity, 'Секция pages не зарегистрирована в config/rabbitmq-events.php');
        $this->assertTrue($entity['enabled']);
        $this->assertSame(Page::class, $entity['model']);
        $this->assertSame(PageEventTransformer::class, $entity['transformer']);
        $this->assertSame('page', $entity['routing_key_prefix']);
        $this->assertSame(['page.created', 'page.updated', 'page.deleted'], $entity['routing_key_patterns']);
    }
}
