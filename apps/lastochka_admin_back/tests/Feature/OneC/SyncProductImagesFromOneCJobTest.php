<?php

declare(strict_types=1);

namespace Tests\Feature\OneC;

use App\Events\ProductUpdated;
use App\Jobs\OneC\SyncProductImagesFromOneCJob;
use App\Models\Product;
use App\Models\Status;
use App\Services\OneC\OneCProductImageSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncProductImagesFromOneCJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Http::fake(['https://img.test/*' => Http::response(
            base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='),
            200,
            ['Content-Type' => 'image/jpeg']
        )]);
    }

    private function product(): Product
    {
        return Product::query()->create(['title' => 'Job фото ' . uniqid(), 'status_id' => Status::CODE_ACTIVE]);
    }

    public function test_applies_photos_and_dispatches_product_updated(): void
    {
        Event::fake([ProductUpdated::class]);
        $product = $this->product();
        $desired = OneCProductImageSync::desiredFromEvent(['image_url' => 'https://img.test/a.jpg']);

        (new SyncProductImagesFromOneCJob($product->id, $desired, now()->toIso8601String()))
            ->handle(app(OneCProductImageSync::class));

        $this->assertSame('https://img.test/a.jpg', $product->fresh()->img->source_url);
        Event::assertDispatched(ProductUpdated::class, fn (ProductUpdated $e) => $e->product->id === $product->id);
    }

    public function test_stale_job_is_skipped(): void
    {
        Event::fake([ProductUpdated::class]);
        $product = $this->product();
        Cache::forever(SyncProductImagesFromOneCJob::latestKey($product->id), now()->toIso8601String());
        $desired = OneCProductImageSync::desiredFromEvent(['image_url' => 'https://img.test/old.jpg']);

        (new SyncProductImagesFromOneCJob($product->id, $desired, now()->subMinute()->toIso8601String()))
            ->handle(app(OneCProductImageSync::class));

        $this->assertNull($product->fresh()->img);
        Event::assertNotDispatched(ProductUpdated::class);
    }

    public function test_queue_from_config_and_timeout_below_retry_after(): void
    {
        $desired = OneCProductImageSync::desiredFromEvent([]);

        $job = new SyncProductImagesFromOneCJob(1, $desired, now()->toIso8601String());
        $this->assertSame('default', $job->queue);
        $this->assertSame(85, $job->timeout);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);

        config()->set('services.onec_photos.queue', 'onec-photos');
        $this->assertSame('onec-photos', (new SyncProductImagesFromOneCJob(1, $desired, now()->toIso8601String()))->queue);
    }

    public function test_no_changes_no_event(): void
    {
        Event::fake([ProductUpdated::class]);
        $product = $this->product();
        $desired = OneCProductImageSync::desiredFromEvent([]);

        (new SyncProductImagesFromOneCJob($product->id, $desired, now()->toIso8601String()))
            ->handle(app(OneCProductImageSync::class));

        Event::assertNotDispatched(ProductUpdated::class);
    }
}
