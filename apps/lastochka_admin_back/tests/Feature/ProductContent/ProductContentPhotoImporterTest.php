<?php

namespace Tests\Feature\ProductContent;

use App\Models\JoinImg;
use App\Models\Product;
use App\Models\Status;
use App\Services\ProductContent\ProductContentPhotoImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductContentPhotoImporterTest extends TestCase
{
  use DatabaseTransactions;

  private ProductContentPhotoImporter $importer;

  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake();
    $this->importer = new ProductContentPhotoImporter();
  }

  private function makeProduct(): Product
  {
    return Product::query()->create([
      'title' => 'Photo Test ' . uniqid(),
      'status_id' => Status::CODE_ACTIVE,
    ]);
  }

  /** Однопиксельный валидный JPEG для фейковых ответов. */
  private function fakeJpegBody(): string
  {
    return base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
  }

  public function test_first_url_becomes_head_rest_become_slider(): void
  {
    Http::fake([
      'cdn.example.com/*' => Http::response($this->fakeJpegBody(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $product = $this->makeProduct();

    $result = $this->importer->import($product, [
      'https://cdn.example.com/main.jpg',
      'https://cdn.example.com/side.jpg',
    ]);

    $this->assertCount(2, $result['saved']);
    $this->assertSame([], $result['errors']);

    $joins = JoinImg::query()
      ->where('item_id', $product->id)
      ->where('table_name', 'products')
      ->get();

    $this->assertCount(2, $joins);
    $this->assertSame(1, $joins->where('head_id', Status::CODE_HEAD_IMG)->count());
    $this->assertSame(1, $joins->where('head_id', Status::CODE_SLIDER_IMG)->count());
  }

  public function test_http_error_and_non_image_reported_with_reason(): void
  {
    Http::fake([
      'cdn.example.com/missing.jpg' => Http::response('', 404),
      'cdn.example.com/page.html' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
      'cdn.example.com/ok.png' => Http::response($this->fakeJpegBody(), 200, ['Content-Type' => 'image/png']),
    ]);

    $product = $this->makeProduct();

    $result = $this->importer->import($product, [
      'https://cdn.example.com/missing.jpg',
      'https://cdn.example.com/page.html',
      'https://cdn.example.com/ok.png',
    ]);

    $this->assertSame(['https://cdn.example.com/ok.png'], $result['saved']);
    $this->assertArrayHasKey('https://cdn.example.com/missing.jpg', $result['errors']);
    $this->assertArrayHasKey('https://cdn.example.com/page.html', $result['errors']);

    // Первый URL (head) не скачался — сохранённое фото ушло в слайдер, НЕ повышено до главного
    $join = JoinImg::query()->where('item_id', $product->id)->first();
    $this->assertSame(Status::CODE_SLIDER_IMG, $join->head_id);
  }

  public function test_content_type_with_parameters_and_human_readable_size(): void
  {
    Http::fake([
      'cdn.example.com/*' => Http::response($this->fakeJpegBody(), 200, ['Content-Type' => 'image/jpeg; charset=binary']),
    ]);

    $product = $this->makeProduct();

    $result = $this->importer->import($product, ['https://cdn.example.com/main.jpg']);

    // Параметризованный Content-Type (с charset) всё равно распознан как jpeg
    $this->assertSame(['https://cdn.example.com/main.jpg'], $result['saved']);

    $join = JoinImg::query()->where('item_id', $product->id)->first();

    // Размер человекочитаемый (Upload::humanReadableSize), а не сырые байты
    $this->assertDoesNotMatchRegularExpression('/^\d+$/', $join->img->size);
  }

  public function test_oversized_image_rejected_with_reason(): void
  {
    $oversizedBody = str_repeat('a', 10 * 1024 * 1024 + 1);

    Http::fake([
      'cdn.example.com/*' => Http::response($oversizedBody, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $product = $this->makeProduct();

    $result = $this->importer->import($product, ['https://cdn.example.com/huge.jpg']);

    $this->assertSame([], $result['saved']);
    $this->assertStringContainsString('больше 10 МБ', $result['errors']['https://cdn.example.com/huge.jpg']);

    $this->assertSame(0, JoinImg::query()->where('item_id', $product->id)->count());
    $this->assertSame([], Storage::allFiles());
  }
}
