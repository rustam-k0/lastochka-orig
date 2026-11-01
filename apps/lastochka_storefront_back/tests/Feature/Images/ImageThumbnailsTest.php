<?php

declare(strict_types=1);

namespace Tests\Feature\Images;

use App\Http\Resources\Api\V1\ImageResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\RabbitMQ\Transformers\BaseEventTransformer;
use App\Services\Images\ImageThumbnails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Превью ~300 px для сетки каталога (thumbPath): мобилка на слабой сети
 * не должна качать полноразмерные картинки ради карточки в сетке.
 */
final class ImageThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'images.optimize.enabled' => true,
            'images.optimize.max_width' => 1200,
            'images.thumbnails.enabled' => true,
            'images.thumbnails.width' => 300,
            'images.thumbnails.quality' => 80,
            'images.optimize.backfill_targets' => ['product_images' => ['path']],
        ]);
    }

    private function photoPng(int $width, int $height): string
    {
        $im = imagecreatetruecolor($width, $height);
        mt_srand(5);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate(
                    $im,
                    min(255, (int) ($x * 255 / $width) + mt_rand(0, 25)),
                    min(255, (int) ($y * 255 / $height) + mt_rand(0, 25)),
                    mt_rand(60, 170),
                ));
            }
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function transformer(): object
    {
        return new class extends BaseEventTransformer
        {
            public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
            {
                return [];
            }

            public function fromEvent(array $eventData): array
            {
                return [];
            }

            public function fetch(string $url, string $folder): string
            {
                return $this->downloadImage($url, $folder);
            }
        };
    }

    #[Test]
    public function thumb_path_is_derived_next_to_the_image(): void
    {
        $this->assertSame('products/thumbs/abc.webp', ImageThumbnails::pathFor('products/abc.png'));
        $this->assertSame('thumbs/abc.webp', ImageThumbnails::pathFor('abc.jpg'));
        $this->assertNull(ImageThumbnails::pathFor('https://cdn.example/abc.jpg'));
        $this->assertNull(ImageThumbnails::pathFor('products/thumbs/abc.webp'));
        $this->assertNull(ImageThumbnails::pathFor(''));
    }

    #[Test]
    public function downloaded_image_gets_a_300px_webp_thumbnail(): void
    {
        Http::fake(['*' => Http::response($this->photoPng(800, 600), 200, ['Content-Type' => 'image/png'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/photo.png', 'products');
        $thumbPath = ImageThumbnails::pathFor($path);

        Storage::disk('public')->assertExists($thumbPath);
        $thumb = Storage::disk('public')->get($thumbPath);
        $info = getimagesizefromstring($thumb);

        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame(300, $info[0]);
        $this->assertSame(225, $info[1]);
        $this->assertLessThan(strlen(Storage::disk('public')->get($path)), strlen($thumb));
    }

    #[Test]
    public function thumbnail_write_failure_does_not_break_image_download(): void
    {
        // Файл на месте каталога thumbs: создать каталог нельзя, как при
        // чужом владельце каталога на сервере.
        Storage::disk('public')->put('products/thumbs', 'not a directory');
        Http::fake(['*' => Http::response($this->photoPng(800, 600), 200, ['Content-Type' => 'image/png'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/photo.png', 'products');

        Storage::disk('public')->assertExists($path);
        $this->assertNull(ImageThumbnails::generate($path));
    }

    #[Test]
    public function backfill_counts_write_failures_and_continues(): void
    {
        $product = Product::factory()->create();
        Storage::disk('public')->put('products/one.png', $this->photoPng(600, 600));
        Storage::disk('public')->put('categories/two.png', $this->photoPng(600, 600));
        Storage::disk('public')->put('products/thumbs', 'not a directory');
        ProductImage::factory()->for($product)->create(['path' => 'products/one.png']);
        Category::factory()->create(['image_path' => 'categories/two.png']);
        config(['images.optimize.backfill_targets' => [
            'product_images' => ['path'],
            'categories' => ['image_path'],
        ]]);

        $this->artisan('images:thumbnails')->assertFailed();

        Storage::disk('public')->assertExists('categories/thumbs/two.webp');
    }

    #[Test]
    public function non_image_download_gets_no_thumbnail(): void
    {
        Http::fake(['*' => Http::response('%PDF-1.4 документ', 200, ['Content-Type' => 'application/pdf'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/spec.pdf', 'documents');

        Storage::disk('public')->assertMissing(ImageThumbnails::pathFor($path));
    }

    #[Test]
    public function resource_returns_thumb_url_when_thumbnail_exists(): void
    {
        Storage::disk('public')->put('products/a.webp', 'full');
        Storage::disk('public')->put('products/thumbs/a.webp', 'thumb');

        $data = ImageResource::fromPath('products/a.webp', 7)->toArray(Request::create('/'));

        $this->assertStringEndsWith('/storage/products/a.webp', $data['path']);
        $this->assertStringEndsWith('/storage/products/thumbs/a.webp', $data['thumbPath']);
    }

    #[Test]
    public function resource_falls_back_to_full_path_without_thumbnail(): void
    {
        Storage::disk('public')->put('products/b.svg', '<svg/>');

        $data = ImageResource::fromPath('products/b.svg', 1)->toArray(Request::create('/'));
        $this->assertSame($data['path'], $data['thumbPath']);

        $external = ImageResource::fromPath('https://cdn.example/c.jpg', 2)->toArray(Request::create('/'));
        $this->assertSame('https://cdn.example/c.jpg', $external['thumbPath']);
    }

    #[Test]
    public function product_api_exposes_thumb_path_for_images(): void
    {
        $store = Store::factory()->create();
        $category = Category::factory()->forStore($store)->create();
        $product = Product::factory()->for($store)->inCategory($category)->create([
            'price' => 100, 'quantity' => 5, 'quantity_step' => 1, 'is_active' => true,
        ]);
        ProductImage::factory()->for($product)->create(['path' => 'products/p.webp', 'position' => 0]);
        ProductImage::factory()->for($product)->create(['path' => 'products/q.webp', 'position' => 1]);
        Storage::disk('public')->put('products/p.webp', 'full');
        Storage::disk('public')->put('products/thumbs/p.webp', 'thumb');
        Storage::disk('public')->put('products/q.webp', 'full');

        $images = $this->getJson("/api/v1/products/{$product->id}")->assertOk()->json('data.images');

        $this->assertStringEndsWith('/storage/products/thumbs/p.webp', $images[0]['thumbPath']);
        $this->assertSame($images[1]['path'], $images[1]['thumbPath']);
    }

    #[Test]
    public function video_entries_have_no_thumb_path(): void
    {
        $product = Product::factory()->create();
        $video = ProductImage::factory()->for($product)->create([
            'path' => 'products/video/v.mp4',
            'type' => 'video',
            'mime_type' => 'video/mp4',
        ]);

        $data = (new ImageResource($video))->toArray(Request::create('/'));

        $this->assertArrayNotHasKey('thumbPath', $data);
    }

    #[Test]
    public function with_thumbnails_adds_thumb_paths_for_deletion(): void
    {
        $this->assertSame(
            ['products/a.webp', 'products/thumbs/a.webp', 'https://cdn.example/x.jpg'],
            ImageThumbnails::withThumbnails(['products/a.webp', 'https://cdn.example/x.jpg', '']),
        );
    }

    #[Test]
    public function backfill_command_creates_missing_thumbnails_and_is_idempotent(): void
    {
        $product = Product::factory()->create();
        Storage::disk('public')->put('products/one.png', $this->photoPng(600, 600));
        Storage::disk('public')->put('products/two.svg', '<svg/>');
        ProductImage::factory()->for($product)->create(['path' => 'products/one.png']);
        ProductImage::factory()->for($product)->create(['path' => 'products/two.svg']);
        ProductImage::factory()->for($product)->create(['path' => 'products/missing.png']);

        $this->artisan('images:thumbnails', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing('products/thumbs/one.webp');

        $this->artisan('images:thumbnails')->assertSuccessful();
        Storage::disk('public')->assertExists('products/thumbs/one.webp');
        Storage::disk('public')->assertMissing('products/thumbs/two.webp');
        $this->assertSame(300, getimagesizefromstring(Storage::disk('public')->get('products/thumbs/one.webp'))[0]);

        Storage::disk('public')->put('products/thumbs/one.webp', 'marker');
        $this->artisan('images:thumbnails')->assertSuccessful();
        $this->assertSame('marker', Storage::disk('public')->get('products/thumbs/one.webp'));

        $this->artisan('images:thumbnails', ['--force' => true])->assertSuccessful();
        $this->assertNotSame('marker', Storage::disk('public')->get('products/thumbs/one.webp'));
    }
}
