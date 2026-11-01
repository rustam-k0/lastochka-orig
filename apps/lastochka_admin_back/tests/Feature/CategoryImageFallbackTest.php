<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Status;
use App\Transformers\CategoryEventTransformer;
use Comrades\RabbitMQEvents\Support\PathNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ALD-131: у категорий, залитых до правки CategoryUpdate (которая с некоторых
 * пор дублирует картинку сразу в слоты 102 и 103), фото могло попасть только
 * в исторический слот 103 (CODE_SITE_SQUARE_IMG). Без fallback такие
 * категории уезжали в витрину с пустым image_url, хотя в админке фото видно.
 */
class CategoryImageFallbackTest extends TestCase
{
    use DatabaseTransactions;

    public function test_to_event_falls_back_to_square_img_when_catalog_img_missing(): void
    {
        $category = Category::create(['title' => 'Only Square ' . uniqid()]);

        // Фото залито только в слот 103 (как было у категорий, созданных
        // до правки CategoryUpdate, дублирующей фото в слот 102).
        $category->saveImg([
            'path' => '/files/categories/square.jpg',
            'origin_name' => 'square.jpg',
            'alt' => null,
            'size' => '1',
            'type' => 'jpg',
        ], Status::CODE_SITE_SQUARE_IMG);

        $category->load('siteCatalogImg', 'siteSquareImg');

        $this->assertNull($category->siteCatalogImg);
        $this->assertNotNull($category->siteSquareImg);

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertArrayHasKey('image_url', $payload);
        $this->assertSame(PathNormalizer::url('/files/categories/square.jpg'), $payload['image_url']);
    }

    public function test_to_event_prefers_catalog_img_over_square_img_when_both_present(): void
    {
        $category = Category::create(['title' => 'Both Slots ' . uniqid()]);

        $category->saveImg([
            'path' => '/files/categories/square.jpg',
            'origin_name' => 'square.jpg',
            'alt' => null,
            'size' => '1',
            'type' => 'jpg',
        ], Status::CODE_SITE_SQUARE_IMG);

        $category->saveImg([
            'path' => '/files/categories/catalog.jpg',
            'origin_name' => 'catalog.jpg',
            'alt' => null,
            'size' => '1',
            'type' => 'jpg',
        ], Status::CODE_SITE_CATALOG_IMG);

        $category->load('siteCatalogImg', 'siteSquareImg');

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertSame(PathNormalizer::url('/files/categories/catalog.jpg'), $payload['image_url']);
    }

    public function test_to_event_image_url_null_when_no_slots_filled(): void
    {
        $category = Category::create(['title' => 'No Image ' . uniqid()]);

        $payload = app(CategoryEventTransformer::class)->toEvent($category);

        $this->assertArrayHasKey('image_url', $payload);
        $this->assertNull($payload['image_url']);
    }
}
