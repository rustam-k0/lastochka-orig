<?php

declare(strict_types=1);

namespace Tests\Feature\Transformers;

use App\Jobs\OneC\SyncProductImagesFromOneCJob;
use App\Models\Product;
use App\Models\Shop;
use App\RabbitMQ\Handlers\ProductBatchHandler;
use App\Services\OneC\OneCProductImageSync;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\InteractsWithOneCCardMaster;
use Tests\TestCase;

class ProductOneCImagesDispatchTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithOneCCardMaster;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        config()->set('rabbitmq-events.enabled', false);
        $this->shop = Shop::create(['title' => 'Images shop', 'code' => 'IM_' . Str::random(6)]);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function batch(array $item, int $minutesAgo = 5): void
    {
        app(ProductBatchHandler::class)->handle([
            'event_id' => (string) Str::uuid(),
            'event_timestamp' => now()->subMinutes($minutesAgo)->toIso8601String(),
            'event_type' => 'batch_updated',
            'event_source' => '1c',
            'source' => '1c',
            'shop_code' => $this->shop->code,
            'items' => [array_merge([
                'id' => 'img-' . Str::random(6),
                'sku' => 'IMG-' . Str::random(6),
                'title' => 'Товар с фото',
                'price' => 100,
                'unit_type' => 'piece',
                'active' => true,
            ], $item)],
        ]);
    }

    public function test_flag_on_dispatches_job_instead_of_inline_download(): void
    {
        $this->setOneCCardMaster(true);
        Bus::fake([SyncProductImagesFromOneCJob::class]);
        Http::fake();

        $this->batch(['image_url' => 'https://img.test/a.jpg', 'additional_image_urls' => ['https://img.test/b.jpg']]);

        Bus::assertDispatched(SyncProductImagesFromOneCJob::class, function (SyncProductImagesFromOneCJob $job) {
            return $job->desired['main_url'] === 'https://img.test/a.jpg'
                && $job->desired['additional_urls'] === ['https://img.test/b.jpg'];
        });
        Http::assertNothingSent();
    }

    public function test_flag_on_without_photo_keys_does_not_dispatch(): void
    {
        $this->setOneCCardMaster(true);
        Bus::fake([SyncProductImagesFromOneCJob::class]);

        $this->batch([]);

        Bus::assertNotDispatched(SyncProductImagesFromOneCJob::class);
    }

    public function test_flag_off_keeps_legacy_inline_download(): void
    {
        $this->setOneCCardMaster(false);
        Bus::fake([SyncProductImagesFromOneCJob::class]);
        Http::fake(['https://img.test/*' => Http::response('x', 404)]);

        $this->batch(['image_url' => 'https://img.test/a.jpg']);

        Bus::assertNotDispatched(SyncProductImagesFromOneCJob::class);
        Http::assertSent(fn ($request) => $request->url() === 'https://img.test/a.jpg');
    }

    public function test_photo_download_failure_does_not_roll_back_card_update(): void
    {
        $this->setOneCCardMaster(true);
        Http::fake(['https://img.test/*' => Http::response('', 404)]);

        $sku = 'BROKEN-' . Str::random(6);
        $title = 'Товар с битым фото ' . Str::random(6);

        $this->batch([
            'id' => 'broken-' . Str::random(6),
            'sku' => $sku,
            'title' => $title,
            'image_url' => 'https://img.test/broken.jpg',
        ]);

        $product = Product::query()->where('articul', $sku)->first();

        $this->assertNotNull($product);
        $this->assertSame($title, $product->title);
        $this->assertNull($product->img);
    }

    public function test_photo_sync_failure_is_reported(): void
    {
        $this->setOneCCardMaster(true);
        Http::fake(['https://img.test/*' => Http::response('', 404)]);
        $handler = $this->spy(ExceptionHandler::class);

        $this->batch(['image_url' => 'https://img.test/broken.jpg']);

        $handler->shouldHaveReceived('report')
            ->with(Mockery::on(fn ($e) => $e instanceof \RuntimeException && str_contains($e->getMessage(), 'не удалось скачать фото')))
            ->once();
    }

    public function test_latest_marker_moves_even_without_sync_so_stale_job_is_skipped(): void
    {
        $this->setOneCCardMaster(true);
        Storage::fake();
        Http::fake(['https://img.test/*' => Http::response(
            base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='),
            200,
            ['Content-Type' => 'image/jpeg']
        )]);
        Bus::fake([SyncProductImagesFromOneCJob::class]);

        $item = ['id' => 'aba-' . Str::random(6), 'sku' => 'ABA-' . Str::random(6)];
        $this->batch($item, 10);
        $product = Product::query()->where('articul', $item['sku'])->firstOrFail();
        $product->saveImg([
            'path' => '/storage/products/onec/a.jpg',
            'origin_name' => 'a.jpg',
            'alt' => 'x',
            'size' => '1 КБ',
            'type' => 'jpg',
            'source_url' => 'https://img.test/a.jpg',
        ]);

        // A -> B: задача B поставлена, но ещё не выполнена.
        $this->batch($item + ['image_url' => 'https://img.test/b.jpg'], 5);
        $jobB = null;
        Bus::assertDispatched(SyncProductImagesFromOneCJob::class, function (SyncProductImagesFromOneCJob $job) use (&$jobB) {
            $jobB = $job;

            return $job->desired['main_url'] === 'https://img.test/b.jpg';
        });

        // B -> A: фото товара уже A, новой задачи нет, но метка времени сдвигается.
        $this->batch($item + ['image_url' => 'https://img.test/a.jpg'], 2);
        Bus::assertDispatchedTimes(SyncProductImagesFromOneCJob::class, 1);

        // Задача B выполняется позже: она устарела и не возвращает фото B.
        $jobB->handle(app(OneCProductImageSync::class));

        $this->assertSame('https://img.test/a.jpg', $product->fresh()->img->source_url);
    }

    public function test_latest_marker_expires(): void
    {
        $this->setOneCCardMaster(true);
        Bus::fake([SyncProductImagesFromOneCJob::class]);
        $item = ['id' => 'ttl-' . Str::random(6), 'sku' => 'TTL-' . Str::random(6)];

        $this->batch($item + ['image_url' => 'https://img.test/a.jpg']);
        $key = SyncProductImagesFromOneCJob::latestKey(Product::query()->where('articul', $item['sku'])->firstOrFail()->id);
        $this->assertNotNull(Cache::get($key));

        $this->travel(47)->hours();
        $this->assertNotNull(Cache::get($key));

        $this->travel(2)->hours();
        $this->assertNull(Cache::get($key), 'метка живёт двое суток, а не вечно');
    }
}
