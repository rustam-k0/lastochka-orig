<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Story;

use App\Models\Story;
use App\Models\StoryImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_stories(): void
    {
        // Arrange
        $story = Story::factory()->create();
        StoryImage::factory()->count(2)->for($story)->create();

        // Act
        $response = $this->getJson('/api/v1/stories');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'title',
                        'thumbnailImage' => ['id', 'path'],
                        'images' => [['id', 'path']],
                        'linkType',
                        'linkProductId',
                        'linkStoreId',
                        'linkExternalUrl',
                        'expiresAt',
                    ],
                ],
            ]);
    }

    public function test_get_stories_excludes_expired(): void
    {
        // Arrange
        Story::factory()->create(); // активная
        Story::factory()->expired()->create(); // истёкшая
        Story::factory()->inactive()->create(); // неактивная

        // Act
        $response = $this->getJson('/api/v1/stories');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_get_story(): void
    {
        // Arrange
        $story = Story::factory()->create();
        StoryImage::factory()->count(3)->for($story)->create();

        // Act
        $response = $this->getJson("/api/v1/stories/{$story->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $story->id)
            ->assertJsonPath('data.title', $story->title)
            ->assertJsonCount(3, 'data.images');
    }

    public function test_get_story_returns_404_for_expired(): void
    {
        // Arrange
        $story = Story::factory()->expired()->create();

        // Act
        $response = $this->getJson("/api/v1/stories/{$story->id}");

        // Assert
        $response->assertNotFound();
    }

    public function test_get_story_returns_404_for_inactive(): void
    {
        // Arrange
        $story = Story::factory()->inactive()->create();

        // Act
        $response = $this->getJson("/api/v1/stories/{$story->id}");

        // Assert
        $response->assertNotFound();
    }

    public function test_get_story_returns_404_for_nonexistent(): void
    {
        // Act
        $response = $this->getJson('/api/v1/stories/999');

        // Assert
        $response->assertNotFound();
    }
}
