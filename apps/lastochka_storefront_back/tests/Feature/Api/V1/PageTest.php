<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PageTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_pages_list(): void
    {
        // Arrange
        Page::factory()->create(['slug' => 'public-offer', 'title' => 'Публичная оферта', 'position' => 2]);
        Page::factory()->create(['slug' => 'privacy-policy', 'title' => 'Политика конфиденциальности', 'position' => 1]);

        // Act
        $response = $this->getJson('/api/v1/pages');

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'privacy-policy')
            ->assertJsonPath('data.1.slug', 'public-offer')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'slug', 'title', 'position', 'publishedAt', 'updatedAt'],
                ],
            ]);

        // Содержимое в списке не отдаётся: список нужен для футера и меню
        $this->assertArrayNotHasKey('content', $response->json('data.0'));
    }

    public function test_list_excludes_unpublished(): void
    {
        Page::factory()->create(['slug' => 'about']);
        Page::factory()->unpublished()->create(['slug' => 'draft']);

        $response = $this->getJson('/api/v1/pages');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_null_position_goes_last(): void
    {
        Page::factory()->create(['slug' => 'no-position', 'position' => null]);
        Page::factory()->create(['slug' => 'with-position', 'position' => 5]);

        $response = $this->getJson('/api/v1/pages');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'with-position')
            ->assertJsonPath('data.1.slug', 'no-position');
    }

    public function test_get_page_by_slug_returns_content_as_object(): void
    {
        Page::factory()->create([
            'slug' => 'privacy-policy',
            'title' => 'Политика конфиденциальности',
            'content' => '{"time":1754730000000,"blocks":[{"type":"header","data":{"text":"1. Общие положения","level":2}}],"version":"2.29.1"}',
            'meta_title' => 'Политика',
            'meta_description' => 'Как мы обрабатываем данные',
        ]);

        $response = $this->getJson('/api/v1/pages/privacy-policy');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'privacy-policy')
            ->assertJsonPath('data.title', 'Политика конфиденциальности')
            ->assertJsonPath('data.metaTitle', 'Политика')
            ->assertJsonPath('data.metaDescription', 'Как мы обрабатываем данные')
            ->assertJsonPath('data.content.blocks.0.type', 'header')
            ->assertJsonPath('data.content.blocks.0.data.text', '1. Общие положения');
    }

    public function test_unpublished_page_returns_404(): void
    {
        Page::factory()->unpublished()->create(['slug' => 'draft']);

        $this->getJson('/api/v1/pages/draft')->assertNotFound();
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/v1/pages/unknown-page')->assertNotFound();
    }

    public function test_soft_deleted_page_returns_404(): void
    {
        $page = Page::factory()->create(['slug' => 'deleted-page']);
        $page->delete();

        $this->getJson('/api/v1/pages/deleted-page')->assertNotFound();

        $response = $this->getJson('/api/v1/pages');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_broken_content_returns_empty_document(): void
    {
        Page::factory()->create(['slug' => 'broken', 'content' => 'не json']);

        $response = $this->getJson('/api/v1/pages/broken');

        $response->assertOk()->assertJsonPath('data.content.blocks', []);
    }

    public function test_duplicate_slug_returns_newest_record(): void
    {
        // На slug намеренно нет уникального индекса. При дубле (например, от
        // повторной обработки события) должна отдаваться самая свежая запись,
        // а не самая старая.
        Page::factory()->create(['slug' => 'duplicate', 'title' => 'Старая версия']);
        $newest = Page::factory()->create(['slug' => 'duplicate', 'title' => 'Новая версия']);

        $response = $this->getJson('/api/v1/pages/duplicate');

        $response->assertOk()
            ->assertJsonPath('data.id', $newest->id)
            ->assertJsonPath('data.title', 'Новая версия');
    }
}
