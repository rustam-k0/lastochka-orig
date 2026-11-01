<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Product;
use App\Models\Store;
use Comrades\RabbitMQEvents\Services\EventConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Регресс на утечку скачанных файлов (2026-07-07).
 *
 * Каждое product.updated с media/image_urls скачивало картинки заново в файл
 * со случайным именем, а старые файлы с диска не удалялись (удалялись только
 * строки product_images): на dev скопилось 16.5k файлов (7.1 GB) при 3.3k
 * живых строк. Плюс лишний HTTP-трафик на каждое событие полной выгрузки 1С.
 *
 * Фикс: источник хранится в source_url; при неизменном наборе URL событие
 * не скачивает и не пересоздаёт строки; при замене — старые файлы удаляются.
 */
final class ProductMediaDownloadLeakTest extends TestCase
{
    use RefreshDatabase;

    private EventConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = new EventConsumer('products', config('rabbitmq-events.entities.products'));

        Storage::fake('public');
    }

    private function fakeCdn(): void
    {
        Http::fake([
            'https://cdn.example.com/*' => Http::response('fake-image-content', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_repeated_event_with_same_media_urls_skips_download_and_keeps_rows(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_1']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_1',
            'shop_code' => 'MSK_LEAK_1',
            'image_url' => 'https://cdn.example.com/products/a.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/a.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $originalImage = $product->images()->firstOrFail();
        $this->assertTrue(Storage::disk('public')->exists($originalImage->path));

        // Повторное событие с тем же URL — как при полной выгрузке 1С без изменений.
        Http::fake(); // сброс: любой новый запрос будет виден как sent
        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_1',
            'shop_code' => 'MSK_LEAK_1',
            'image_url' => 'https://cdn.example.com/products/a.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/a.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($updated);

        Http::assertNothingSent();

        $images = $product->images()->get();
        $this->assertCount(1, $images);
        $this->assertSame($originalImage->id, $images->first()->id);
        $this->assertSame($originalImage->path, $images->first()->path);
        $this->assertTrue(Storage::disk('public')->exists($originalImage->path));
    }

    public function test_changed_media_url_downloads_new_file_and_deletes_old_one(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_2']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_2',
            'shop_code' => 'MSK_LEAK_2',
            'image_url' => 'https://cdn.example.com/products/old.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/old.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $oldPath = $product->images()->firstOrFail()->path;

        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_2',
            'shop_code' => 'MSK_LEAK_2',
            'image_url' => 'https://cdn.example.com/products/new.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/new.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($updated);

        $images = $product->images()->get();
        $this->assertCount(1, $images);
        $this->assertSame('https://cdn.example.com/products/new.jpg', $images->first()->source_url);
        $this->assertTrue(Storage::disk('public')->exists($images->first()->path));
        // Старый файл не должен остаться сиротой на диске.
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
    }

    public function test_partial_media_change_reuses_kept_file_and_deletes_removed_one(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_3']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_3',
            'shop_code' => 'MSK_LEAK_3',
            'image_url' => 'https://cdn.example.com/products/keep.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/keep.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
                ['url' => 'https://cdn.example.com/products/drop.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 1],
            ],
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $keptPath = $product->images()->where('source_url', 'https://cdn.example.com/products/keep.jpg')->firstOrFail()->path;
        $droppedPath = $product->images()->where('source_url', 'https://cdn.example.com/products/drop.jpg')->firstOrFail()->path;

        Http::fake([
            'https://cdn.example.com/products/added.jpg' => Http::response('fake', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_3',
            'shop_code' => 'MSK_LEAK_3',
            'image_url' => 'https://cdn.example.com/products/keep.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/keep.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
                ['url' => 'https://cdn.example.com/products/added.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 1],
            ],
        ]);
        $this->consumer->processEvent($updated);

        // keep.jpg не перекачивался — только added.jpg.
        Http::assertSentCount(1);

        $images = $product->images()->orderBy('position')->get();
        $this->assertCount(2, $images);
        $this->assertSame($keptPath, $images[0]->path, 'файл keep.jpg должен переиспользоваться');
        $this->assertTrue(Storage::disk('public')->exists($keptPath));
        $this->assertFalse(Storage::disk('public')->exists($droppedPath), 'файл drop.jpg должен быть удалён');
    }

    public function test_legacy_rows_without_source_url_converge_and_old_files_are_cleaned(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_4']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_4',
            'shop_code' => 'MSK_LEAK_4',
            'image_url' => 'https://cdn.example.com/products/a.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/a.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $image = $product->images()->firstOrFail();
        $legacyPath = $image->path;
        // Строка времён до деплоя фикса: source_url неизвестен.
        $image->update(['source_url' => null]);

        $updated = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_4',
            'shop_code' => 'MSK_LEAK_4',
            'image_url' => 'https://cdn.example.com/products/a.jpg',
            'media' => [
                ['url' => 'https://cdn.example.com/products/a.jpg', 'type' => 'image', 'mime_type' => 'jpg', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($updated);

        $fresh = $product->images()->firstOrFail();
        $this->assertSame('https://cdn.example.com/products/a.jpg', $fresh->source_url);
        $this->assertTrue(Storage::disk('public')->exists($fresh->path));
        // Старый файл legacy-строки удалён — сироты не копятся.
        $this->assertFalse(Storage::disk('public')->exists($legacyPath));
    }

    public function test_video_same_url_skips_download_and_changed_url_deletes_old_file(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_5']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_5',
            'shop_code' => 'MSK_LEAK_5',
            'video_path' => 'https://cdn.example.com/video/v1.mp4',
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $oldVideoPath = $product->video_path;
        $this->assertNotNull($oldVideoPath);
        $this->assertTrue(Storage::disk('public')->exists($oldVideoPath));

        // Тот же URL — скачивания нет, путь не меняется.
        Http::fake();
        $sameUrl = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_5',
            'shop_code' => 'MSK_LEAK_5',
            'video_path' => 'https://cdn.example.com/video/v1.mp4',
        ]);
        $this->consumer->processEvent($sameUrl);

        Http::assertNothingSent();
        $this->assertSame($oldVideoPath, $product->fresh()->video_path);

        // Новый URL — новый файл, старый удалён.
        $this->fakeCdn();
        $newUrl = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_5',
            'shop_code' => 'MSK_LEAK_5',
            'video_path' => 'https://cdn.example.com/video/v2.mp4',
        ]);
        $this->consumer->processEvent($newUrl);

        $fresh = $product->fresh();
        $this->assertNotSame($oldVideoPath, $fresh->video_path);
        $this->assertTrue(Storage::disk('public')->exists($fresh->video_path));
        $this->assertFalse(Storage::disk('public')->exists($oldVideoPath));
    }

    public function test_video_removed_deletes_old_file(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_6']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_6',
            'shop_code' => 'MSK_LEAK_6',
            'video_path' => 'https://cdn.example.com/video/v1.mp4',
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $oldVideoPath = $product->video_path;

        $removed = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_6',
            'shop_code' => 'MSK_LEAK_6',
            'video_path' => null,
        ]);
        $this->consumer->processEvent($removed);

        $this->assertNull($product->fresh()->video_path);
        $this->assertFalse(Storage::disk('public')->exists($oldVideoPath));
    }

    public function test_documents_same_urls_reuse_files_and_removed_documents_files_are_deleted(): void
    {
        Store::factory()->create(['code' => 'MSK_LEAK_7']);
        $this->fakeCdn();

        $created = $this->buildEventData([
            'event_type' => 'created',
            'id' => 'prod_leak_7',
            'shop_code' => 'MSK_LEAK_7',
            'documents' => [
                ['title' => 'Сертификат', 'file_url' => 'https://cdn.example.com/docs/cert.pdf', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($created);

        $product = Product::firstOrFail();
        $docPath = $product->documents()->firstOrFail()->file_path;
        $this->assertTrue(Storage::disk('public')->exists($docPath));

        // Тот же документ — файл переиспользуется, скачивания нет.
        Http::fake();
        $same = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_7',
            'shop_code' => 'MSK_LEAK_7',
            'documents' => [
                ['title' => 'Сертификат', 'file_url' => 'https://cdn.example.com/docs/cert.pdf', 'position' => 0],
            ],
        ]);
        $this->consumer->processEvent($same);

        Http::assertNothingSent();
        $this->assertSame($docPath, $product->documents()->firstOrFail()->file_path);
        $this->assertTrue(Storage::disk('public')->exists($docPath));

        // Документы сняли — строки и файлы удалены.
        $removed = $this->buildEventData([
            'event_type' => 'updated',
            'id' => 'prod_leak_7',
            'shop_code' => 'MSK_LEAK_7',
            'documents' => [],
        ]);
        $this->consumer->processEvent($removed);

        $this->assertSame(0, $product->documents()->count());
        $this->assertFalse(Storage::disk('public')->exists($docPath));
    }

    private function buildEventData(array $overrides = []): array
    {
        return array_merge([
            'event_id' => Str::uuid()->toString(),
            'event_type' => 'created',
            'event_timestamp' => now()->toIso8601String(),
            'id' => 'prod_'.Str::random(5),
            'source' => 'bagisto',
            'shop_code' => 'TEST_'.Str::random(3),
            'sku' => 'SKU-'.Str::random(5),
            'title' => 'Тестовый товар',
            'price' => 100.00,
            'active' => true,
        ], $overrides);
    }
}
