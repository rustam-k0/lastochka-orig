<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class PagePublishTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    private function makeAdminUser(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'surname' => 'Fixture',
            'email' => 'admin_' . uniqid() . '@example.com',
            'phone' => '+7900' . random_int(1000000, 9999999),
            'password' => bcrypt('password123'),
        ]);
        $user->assignRole(Role::findOrCreate(Role::ADMIN));

        return $user;
    }

    private function publish(User $user, int $id)
    {
        return $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                PagePublish(id: $id) {
                    id
                    is_published
                    published_at
                }
            }
        ', ['id' => $id]);
    }

    private function unpublish(User $user, int $id)
    {
        return $this->actingAs($user)->graphQL('
            mutation ($id: Int!) {
                PageUnpublish(id: $id) {
                    id
                    is_published
                    published_at
                }
            }
        ', ['id' => $id]);
    }

    public function test_publish_sets_flag_and_published_at(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create(['slug' => 'offer-' . uniqid()]);

        $response = $this->publish($user, $page->id);

        $response->assertJsonPath('data.PagePublish.is_published', true);
        $this->assertNotNull($page->fresh()->published_at);
    }

    public function test_unpublish_keeps_published_at(): void
    {
        $user = $this->makeAdminUser();
        // Дата первой публикации намеренно состарена на несколько дней: `published_at`
        // хранится с точностью до секунды, поэтому без разноса во времени перезапись
        // на свежий `now()` могла бы случайно попасть в ту же секунду и замаскировать баг.
        $page = Page::factory()->published()->create([
            'slug' => 'offer-' . uniqid(),
            'published_at' => now()->subDays(10),
        ]);
        $publishedAt = $page->published_at;

        $response = $this->unpublish($user, $page->id);

        $response->assertJsonPath('data.PageUnpublish.is_published', false);
        $this->assertNotNull($page->fresh()->published_at);
        $this->assertEquals($publishedAt->timestamp, $page->fresh()->published_at->timestamp);
    }

    public function test_republish_does_not_overwrite_published_at(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create([
            'slug' => 'offer-' . uniqid(),
            'is_published' => false,
            'published_at' => now()->subDays(10),
        ]);
        $originalPublishedAt = $page->published_at;

        $this->publish($user, $page->id);

        $this->assertEquals($originalPublishedAt->timestamp, $page->fresh()->published_at->timestamp);
    }

    public function test_publish_rejects_page_with_empty_content(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create([
            'slug' => 'offer-' . uniqid(),
            'content' => json_encode(['blocks' => []], JSON_UNESCAPED_UNICODE),
            'is_published' => false,
        ]);

        $response = $this->publish($user, $page->id);

        $response->assertJsonPath('errors.0.message', 'Содержимое страницы обязательно: добавьте хотя бы один блок текста');
        $this->assertFalse($page->fresh()->is_published);
        $this->assertNull($page->fresh()->published_at);
    }

    public function test_publish_still_works_for_page_with_content(): void
    {
        $user = $this->makeAdminUser();
        $page = Page::factory()->create([
            'slug' => 'offer-' . uniqid(),
            'content' => json_encode([
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Текст страницы']],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'is_published' => false,
        ]);

        $response = $this->publish($user, $page->id);

        $response->assertJsonPath('data.PagePublish.is_published', true);
        $this->assertTrue($page->fresh()->is_published);
        $this->assertNotNull($page->fresh()->published_at);
    }
}
