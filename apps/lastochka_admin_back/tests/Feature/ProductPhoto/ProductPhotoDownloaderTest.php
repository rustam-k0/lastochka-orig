<?php

declare(strict_types=1);

namespace Tests\Feature\ProductPhoto;

use App\Services\ProductPhoto\ProductPhotoDownloader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductPhotoDownloaderTest extends TestCase
{
  private function jpeg(): string
  {
    return base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
  }

  public function test_saves_into_given_folder(): void
  {
    Storage::fake();
    Http::fake(['https://img.test/a.jpg' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    $img = (new ProductPhotoDownloader())->download('https://img.test/a.jpg', 'Товар', 'products/onec');

    $this->assertStringContainsString('products/onec/', $img['path']);
    $this->assertSame('a.jpg', $img['origin_name']);
    $this->assertSame('jpg', $img['type']);
    $this->assertSame('Товар', $img['alt']);
  }

  /**
   * Кириллический домен (ссылки 1С Разгуляйки на русский-разгуляйка.рф) уходит
   * punycode-хостом: сырой UTF-8 в Host nginx сайта отбивает ответом 400.
   */
  public function test_requests_cyrillic_domain_as_punycode(): void
  {
    Storage::fake();
    Http::fake([
      'https://xn--80akhbyknj4f.xn--p1ai/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
      '*' => Http::response('Bad Request', 400, ['Content-Type' => 'text/html']),
    ]);

    $img = (new ProductPhotoDownloader())->download('https://испытание.рф/i/t/a.jpg', 'Товар', 'products/onec');

    $this->assertSame('a.jpg', $img['origin_name']);
    Http::assertSent(fn ($request) => $request->toPsrRequest()->getUri()->getHost() === 'xn--80akhbyknj4f.xn--p1ai');
  }

  public function test_throws_on_non_image(): void
  {
    Storage::fake();
    Http::fake(['https://img.test/page' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);

    $this->expectException(\RuntimeException::class);
    (new ProductPhotoDownloader())->download('https://img.test/page', 'Товар');
  }
}
