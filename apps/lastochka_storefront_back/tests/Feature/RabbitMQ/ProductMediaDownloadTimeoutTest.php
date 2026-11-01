<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ALD-133 (fix round 2): downloadImage() снизил дефолтный таймаут одной
 * попытки до 5 сек, рассчитанных на картинки в десятки килобайт. Но метод
 * вызывается ещё и для видео товара (processVideo/processMedia c
 * type=video) и документов товара (processDocuments) — файлов на
 * мегабайты, для которых 5 сек тихо режут скачивание (retry не спасает,
 * т.к. таймаут — это бюджет на всю передачу, а не только на подключение).
 *
 * Эти вызовы обязаны передавать увеличенный таймаут (см.
 * BaseEventTransformer::LARGE_FILE_DOWNLOAD_TIMEOUT = 30). Подменяем
 * Http-фасад целиком (а не Http::fake(), который таймаут не хранит и не
 * позволяет его проверить) и фиксируем, какой timeout() реально был
 * передан на каждый URL перед get().
 */
final class ProductMediaDownloadTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_and_document_downloads_use_extended_timeout_image_keeps_default(): void
    {
        \App\Models\Store::factory()->create(['code' => 'MSK_TIMEOUT_1']);
        Storage::fake('public');

        $capturedTimeouts = [];

        Http::shouldReceive('retry')
            ->andReturnUsing(function () use (&$capturedTimeouts) {
                return new class($capturedTimeouts)
                {
                    private array $timeouts;

                    private int $currentTimeout = 5;

                    public function __construct(&$timeouts)
                    {
                        $this->timeouts = &$timeouts;
                    }

                    public function timeout($seconds): static
                    {
                        $this->currentTimeout = $seconds;

                        return $this;
                    }

                    public function get($url)
                    {
                        $this->timeouts[$url] = $this->currentTimeout;

                        $contentType = match (true) {
                            str_ends_with($url, '.mp4') => 'video/mp4',
                            str_ends_with($url, '.pdf') => 'application/pdf',
                            default => 'image/jpeg',
                        };

                        return new Response(new Psr7Response(200, ['Content-Type' => $contentType], 'fake-content'));
                    }
                };
            });

        $consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        $eventData = [
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_timeout_1',
            'source' => 'bagisto',
            'shop_code' => 'MSK_TIMEOUT_1',
            'sku' => 'SKU-TIMEOUT-1',
            'title' => 'Товар с медиа и документами',
            'price' => 100.00,
            'active' => true,
            'video_path' => 'https://cdn.example.com/video/v1.mp4',
            'documents' => [
                ['title' => 'Сертификат', 'file_url' => 'https://cdn.example.com/docs/cert.pdf', 'position' => 0],
            ],
            'media' => [
                ['url' => 'https://cdn.example.com/products/a.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
                ['url' => 'https://cdn.example.com/products/b.mp4', 'type' => 'video', 'mime_type' => 'mp4', 'position' => 1],
            ],
        ];

        $result = $consumer->processEvent($eventData);

        $this->assertEquals('ack', $result);
        $this->assertNotNull(Product::where('sku', 'SKU-TIMEOUT-1')->first());

        // Видео товара (processVideo) — увеличенный таймаут.
        $this->assertSame(30, $capturedTimeouts['https://cdn.example.com/video/v1.mp4'] ?? null);
        // Документ товара (processDocuments) — увеличенный таймаут.
        $this->assertSame(30, $capturedTimeouts['https://cdn.example.com/docs/cert.pdf'] ?? null);
        // Картинка в media (type=image, processMedia) — дефолтный таймаут.
        $this->assertSame(5, $capturedTimeouts['https://cdn.example.com/products/a.jpg'] ?? null);
        // Видео в media (type=video, processMedia) — увеличенный таймаут.
        $this->assertSame(30, $capturedTimeouts['https://cdn.example.com/products/b.mp4'] ?? null);
    }
}
