<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class StoryCustomCheckboxesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
        AdminSetting::where('key', 'entity_checkboxes')->delete();
        AdminSetting::setValue('entity_checkboxes', json_encode([
            'Story' => [
                ['field' => 'is_promo', 'label' => 'Промо', 'enabled' => true, 'custom' => true],
            ],
        ]), 'json');
    }

    private function imageInput(): array
    {
        return [
            'path' => '/files/test/story.jpg',
            'origin_name' => 'story.jpg',
            'alt' => 'story',
            'size' => '100',
            'type' => 'image/jpeg',
        ];
    }

    /** @test */
    public function story_create_stores_sanitized_custom_checkboxes(): void
    {
        $title = 'История ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String!, $expires_at: DateTime!, $thumbnail_image: ImgInput!, $images: [ImgInput!]!, $custom_checkboxes: String) {
                StoryCreate(title: $title, expires_at: $expires_at, thumbnail_image: $thumbnail_image, images: $images, custom_checkboxes: $custom_checkboxes) {
                    id
                    title
                }
            }
        ', [
            'title' => $title,
            'expires_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'thumbnail_image' => $this->imageInput(),
            'images' => [$this->imageInput()],
            'custom_checkboxes' => json_encode(['is_promo' => true, 'unknown' => true]),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $story = Story::where('title', $title)->firstOrFail();
        $this->assertSame(['is_promo' => true], json_decode($story->custom_checkboxes, true));
    }

    /** @test */
    public function story_update_without_key_keeps_stored_value(): void
    {
        $story = Story::create([
            'title' => 'История ' . uniqid(),
            'expires_at' => now()->addMonth(),
            'custom_checkboxes' => json_encode((object) ['is_promo' => true]),
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $title: String) {
                StoryUpdate(id: $id, title: $title) { id title }
            }
        ', ['id' => $story->id, 'title' => 'Обновлена ' . uniqid()]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));
        $this->assertSame(['is_promo' => true], json_decode($story->fresh()->custom_checkboxes, true));
    }
}
