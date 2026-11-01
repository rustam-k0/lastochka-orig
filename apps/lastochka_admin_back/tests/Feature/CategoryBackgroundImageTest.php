<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Status;
use App\Transformers\CategoryEventTransformer;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CategoryBackgroundImageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_save_background_img_creates_join_with_background_code(): void
    {
        $category = Category::create(['title' => 'BG ' . uniqid()]);

        $category->saveImg([
            'path' => '/files/categories/bg.jpg',
            'origin_name' => 'bg.jpg',
            'alt' => null,
            'size' => '1',
            'type' => 'jpg',
        ], Status::CODE_BACKGROUND_IMG);

        $category->load('backgroundImg', 'joinBackgroundImg');

        $this->assertNotNull($category->backgroundImg);
        $this->assertSame('/files/categories/bg.jpg', $category->backgroundImg->path);
        $this->assertNotNull($category->joinBackgroundImg);
        $this->assertSame(Status::CODE_BACKGROUND_IMG, (int) $category->joinBackgroundImg->head_id);
        $this->assertSame('categories', $category->joinBackgroundImg->table_name);
    }

    public function test_to_event_includes_background_image_url(): void
    {
        $category = Category::create(['title' => 'BG Event ' . uniqid()]);
        $category->saveImg([
            'path' => '/files/categories/bg.jpg',
            'origin_name' => 'bg.jpg',
            'alt' => null,
            'size' => '1',
            'type' => 'jpg',
        ], Status::CODE_BACKGROUND_IMG);
        $category->load('backgroundImg');

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertArrayHasKey('background_image_url', $payload);
        $this->assertSame(PathNormalizer::url('/files/categories/bg.jpg'), $payload['background_image_url']);
    }

    public function test_to_event_background_image_url_null_when_absent(): void
    {
        $category = Category::create(['title' => 'No BG ' . uniqid()]);

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertArrayHasKey('background_image_url', $payload);
        $this->assertNull($payload['background_image_url']);
    }
}
