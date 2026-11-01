<?php

declare(strict_types=1);

namespace Tests\Feature\OneC;

use App\Models\Img;
use App\Models\JoinImg;
use App\Models\Product;
use App\Models\Status;
use App\Services\OneC\OneCProductImageSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OneCProductImageSyncTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
        Http::fake([
            'https://img.test/broken*' => Http::response('', 404),
            'https://img.test/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function sync(): OneCProductImageSync
    {
        return app(OneCProductImageSync::class);
    }

    private function product(): Product
    {
        return Product::query()->create(['title' => 'Фото 1С ' . uniqid(), 'status_id' => Status::CODE_ACTIVE]);
    }

    private function desired(array $event): array
    {
        return OneCProductImageSync::desiredFromEvent($event);
    }

    private function sliderSources(Product $product): array
    {
        return $product->fresh()->images->pluck('source_url')->all();
    }

    public function test_desired_from_event(): void
    {
        $this->assertSame(
            ['main_provided' => false, 'main_url' => null, 'additional_provided' => false, 'additional_urls' => null],
            $this->desired([])
        );
        $this->assertSame(
            ['main_provided' => true, 'main_url' => null, 'additional_provided' => true, 'additional_urls' => []],
            $this->desired(['image_url' => '  ', 'additional_image_urls' => null])
        );
        $this->assertSame(
            ['main_provided' => true, 'main_url' => 'https://img.test/a.jpg', 'additional_provided' => true, 'additional_urls' => ['https://img.test/b.jpg', 'https://img.test/c.jpg']],
            $this->desired(['image_url' => 'https://img.test/a.jpg', 'additional_image_urls' => ['https://img.test/b.jpg', '', 'https://img.test/b.jpg', 'https://img.test/c.jpg']])
        );
        $this->assertFalse($this->desired(['additional_image_urls' => 'https://img.test/b.jpg'])['additional_provided']);
    }

    public function test_sets_new_main_photo_with_source_url(): void
    {
        $product = $this->product();

        $changed = $this->sync()->sync($product, $this->desired(['image_url' => 'https://img.test/a.jpg']));

        $this->assertTrue($changed);
        $this->assertSame('https://img.test/a.jpg', $product->fresh()->img->source_url);
    }

    public function test_same_main_url_is_noop(): void
    {
        $product = $this->product();
        $desired = $this->desired(['image_url' => 'https://img.test/a.jpg']);
        $this->sync()->sync($product, $desired);

        $this->assertFalse($this->sync()->needsSync($product->fresh(), $desired));
        $this->assertFalse($this->sync()->sync($product->fresh(), $desired));
    }

    public function test_replaces_manual_main_photo(): void
    {
        $product = $this->product();
        $product->saveImg(['path' => '/files/cropped/manual.jpg', 'origin_name' => 'manual.jpg', 'alt' => 'x', 'size' => '1 КБ', 'type' => 'jpg']);

        $this->sync()->sync($product->fresh(), $this->desired(['image_url' => 'https://img.test/new.jpg']));

        $img = $product->fresh()->img;
        $this->assertSame('https://img.test/new.jpg', $img->source_url);
        $this->assertStringContainsString('products/onec/', $img->path);
    }

    public function test_null_main_url_removes_main_photo(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['image_url' => 'https://img.test/a.jpg']));

        $changed = $this->sync()->sync($product->fresh(), $this->desired(['image_url' => null]));

        $this->assertTrue($changed);
        $this->assertNull($product->fresh()->img);
    }

    public function test_slider_follows_list_order_and_reuses_downloaded(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['additional_image_urls' => ['https://img.test/b.jpg', 'https://img.test/c.jpg']]));
        $reusedImgId = Img::query()->where('source_url', 'https://img.test/c.jpg')->value('id');

        $this->sync()->sync($product->fresh(), $this->desired(['additional_image_urls' => ['https://img.test/c.jpg', 'https://img.test/d.jpg']]));

        $this->assertSame(['https://img.test/c.jpg', 'https://img.test/d.jpg'], $this->sliderSources($product));
        $this->assertSame($reusedImgId, $product->fresh()->images->first()->id, 'скачанное ранее фото не качается заново');
    }

    public function test_empty_list_clears_slider(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['additional_image_urls' => ['https://img.test/b.jpg']]));

        $this->sync()->sync($product->fresh(), $this->desired(['additional_image_urls' => []]));

        $this->assertSame([], $this->sliderSources($product));
    }

    public function test_partial_slider_failure_keeps_downloaded(): void
    {
        $product = $this->product();

        $changed = $this->sync()->sync($product, $this->desired(['additional_image_urls' => ['https://img.test/broken.jpg', 'https://img.test/e.jpg']]));

        $this->assertTrue($changed);
        $this->assertSame(['https://img.test/e.jpg'], $this->sliderSources($product));
    }

    public function test_total_failure_keeps_old_photos_and_throws(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['image_url' => 'https://img.test/a.jpg', 'additional_image_urls' => ['https://img.test/b.jpg']]));

        try {
            $this->sync()->sync($product->fresh(), $this->desired(['image_url' => 'https://img.test/broken-1.jpg', 'additional_image_urls' => ['https://img.test/broken-2.jpg']]));
            $this->fail('ожидалось исключение для ретрая очереди');
        } catch (\RuntimeException) {
        }

        $fresh = $product->fresh();
        $this->assertSame('https://img.test/a.jpg', $fresh->img->source_url);
        $this->assertSame(['https://img.test/b.jpg'], $this->sliderSources($product));
    }

    public function test_manual_replacement_of_onec_main_photo_is_resynced_from_same_url(): void
    {
        $product = $this->product();
        $desired = $this->desired(['image_url' => 'https://img.test/a.jpg']);
        $this->sync()->sync($product, $desired);

        $fresh = $product->fresh();
        $fresh->updateImg($fresh->joinImg, [
            'path' => '/files/cropped/manual.jpg',
            'origin_name' => 'manual.jpg',
            'alt' => 'x',
            'size' => '1 КБ',
            'type' => 'jpg',
        ]);

        $this->assertTrue($this->sync()->needsSync($product->fresh(), $desired), 'фото из 1С заменили вручную: обмен с тем же URL обязан вернуть его');
        $this->assertTrue($this->sync()->sync($product->fresh(), $desired));
        $this->assertSame('https://img.test/a.jpg', $product->fresh()->img->source_url);
    }

    public function test_manual_slider_photo_is_replaced_by_onec_list(): void
    {
        $product = $this->product();
        $product->saveImg(['path' => '/files/cropped/manual-slide.jpg', 'origin_name' => 'manual-slide.jpg', 'alt' => 'x', 'size' => '1 КБ', 'type' => 'jpg'], Status::CODE_SLIDER_IMG);
        $desired = $this->desired(['additional_image_urls' => ['https://img.test/b.jpg', 'https://img.test/c.jpg']]);

        $this->assertTrue($this->sync()->needsSync($product->fresh(), $desired));
        $this->assertTrue($this->sync()->sync($product->fresh(), $desired));

        $this->assertSame(['https://img.test/b.jpg', 'https://img.test/c.jpg'], $this->sliderSources($product));
        $this->assertSame(2, JoinImg::query()->where('item_id', $product->id)->where('table_name', $product->getTable())->where('head_id', Status::CODE_SLIDER_IMG)->count());
    }

    public function test_unchanged_slider_is_noop(): void
    {
        $product = $this->product();
        $desired = $this->desired(['additional_image_urls' => ['https://img.test/b.jpg', 'https://img.test/c.jpg']]);
        $this->sync()->sync($product, $desired);
        $joinIds = JoinImg::query()->where('item_id', $product->id)->where('head_id', Status::CODE_SLIDER_IMG)->orderBy('id')->pluck('id')->all();

        $this->assertFalse($this->sync()->needsSync($product->fresh(), $desired));
        $this->assertFalse($this->sync()->sync($product->fresh(), $desired));
        $this->assertSame($joinIds, JoinImg::query()->where('item_id', $product->id)->where('head_id', Status::CODE_SLIDER_IMG)->orderBy('id')->pluck('id')->all());
    }

    public function test_needs_sync_reads_main_photo_once(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['image_url' => 'https://img.test/a.jpg']));
        $fresh = $product->fresh();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->sync()->needsSync($fresh, $this->desired(['image_url' => 'https://img.test/a.jpg']));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $joinQueries = array_filter($queries, fn (array $q) => str_contains($q['query'], 'join_img'));
        $this->assertCount(1, $joinQueries, 'связка главного фото читается один раз');
    }

    private function urlOfLength(int $length, string $fill): string
    {
        $prefix = 'https://img.test/';

        return $prefix . str_repeat($fill, $length - mb_strlen($prefix));
    }

    public function test_url_of_max_length_is_accepted(): void
    {
        $limit = $this->urlOfLength(1000, 'b');

        $this->assertSame($limit, $this->desired(['image_url' => $limit])['main_url']);
        $this->assertSame([$limit], $this->desired(['additional_image_urls' => [$limit]])['additional_urls']);
    }

    public function test_too_long_main_url_leaves_main_photo_untouched(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['image_url' => 'https://img.test/a.jpg']));
        Log::spy();

        $desired = $this->desired(['image_url' => $this->urlOfLength(1001, 'a')]);

        $this->assertFalse($desired['main_provided'], 'длинная ссылка не должна означать «удалить фото»');
        $this->assertFalse($this->sync()->needsSync($product->fresh(), $desired));
        $this->assertSame('https://img.test/a.jpg', $product->fresh()->img->source_url);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_too_long_url_in_list_leaves_slider_untouched(): void
    {
        $product = $this->product();
        $this->sync()->sync($product, $this->desired(['additional_image_urls' => ['https://img.test/b.jpg']]));
        Log::spy();

        $desired = $this->desired(['additional_image_urls' => [$this->urlOfLength(1001, 'a'), 'https://img.test/c.jpg']]);

        $this->assertFalse($desired['additional_provided'], 'слайдер не меняется, если в списке есть битая ссылка');
        $this->assertFalse($this->sync()->needsSync($product->fresh(), $desired));
        $this->assertSame(['https://img.test/b.jpg'], $this->sliderSources($product));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_absent_keys_do_not_touch_photos(): void
    {
        $product = $this->product();
        $product->saveImg(['path' => '/files/cropped/manual.jpg', 'origin_name' => 'manual.jpg', 'alt' => 'x', 'size' => '1 КБ', 'type' => 'jpg']);

        $this->assertFalse($this->sync()->needsSync($product->fresh(), $this->desired([])));
        $this->assertSame('/files/cropped/manual.jpg', $product->fresh()->img->path);
        $this->assertSame(1, JoinImg::query()->where('item_id', $product->id)->where('head_id', Status::CODE_HEAD_IMG)->count());
    }
}
