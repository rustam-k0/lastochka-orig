<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Transformers\CategoryEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CategoryTagTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tag_fields_are_fillable(): void
    {
        $category = Category::create([
            'title' => 'Tag ' . uniqid(),
            'tag_text' => 'Готовим свежие каждый день',
            'tag_color' => '#FF5733',
        ]);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'tag_text' => 'Готовим свежие каждый день',
            'tag_color' => '#FF5733',
        ]);
    }

    public function test_to_event_includes_tag_fields(): void
    {
        $category = Category::create([
            'title' => 'Tag Event ' . uniqid(),
            'tag_text' => 'Свежая выпечка',
            'tag_color' => '#00AA00',
        ]);

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertSame('Свежая выпечка', $payload['tag_text']);
        $this->assertSame('#00AA00', $payload['tag_color']);
    }

    public function test_to_event_tag_fields_null_when_absent(): void
    {
        $category = Category::create(['title' => 'No Tag ' . uniqid()]);

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertArrayHasKey('tag_text', $payload);
        $this->assertArrayHasKey('tag_color', $payload);
        $this->assertNull($payload['tag_text']);
        $this->assertNull($payload['tag_color']);
    }
}
