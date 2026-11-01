<?php

declare(strict_types=1);

namespace Tests\Feature\Images;

use App\RabbitMQ\Transformers\BaseEventTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Картинки, приезжающие из admin_back через RabbitMQ, должны ложиться
 * на диск уже оптимизированными — иначе мобильное приложение продолжит
 * качать оригиналы по 240–320 КБ.
 */
final class DownloadedImagesAreOptimizedTest extends TestCase
{
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

            /** Открывает protected downloadImage для проверки. */
            public function fetch(string $url, string $folder): string
            {
                return $this->downloadImage($url, $folder);
            }
        };
    }

    private function photoLikePng(int $width, int $height): string
    {
        $im = imagecreatetruecolor($width, $height);
        mt_srand(7);
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

    #[Test]
    public function downloaded_photo_is_stored_as_webp_and_gets_smaller(): void
    {
        Storage::fake('public');
        config(['images.optimize.enabled' => true, 'images.optimize.max_width' => 400]);

        $source = $this->photoLikePng(600, 600);
        Http::fake(['*' => Http::response($source, 200, ['Content-Type' => 'image/png'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/products/photo.png', 'products');

        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $stored = Storage::disk('public')->get($path);
        $this->assertLessThan(strlen($source), strlen($stored));

        $info = getimagesizefromstring($stored);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame(400, $info[0], 'Ширина должна быть ограничена max_width');
    }

    #[Test]
    public function narrow_photo_is_not_upscaled(): void
    {
        Storage::fake('public');
        config(['images.optimize.enabled' => true, 'images.optimize.max_width' => 400]);

        Http::fake(['*' => Http::response($this->photoLikePng(300, 200), 200, ['Content-Type' => 'image/png'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/products/small.png', 'products');
        $info = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertSame(300, $info[0]);
        $this->assertSame(200, $info[1]);
    }

    #[Test]
    public function mime_type_is_derived_from_the_stored_file(): void
    {
        $transformer = new class extends BaseEventTransformer
        {
            public function toEvent(Model $model, ?object $relatedModel = null, ?string $eventId = null): array
            {
                return [];
            }

            public function fromEvent(array $eventData): array
            {
                return [];
            }

            public function mimeFor(string $path, ?string $fallback = null): ?string
            {
                return $this->mimeTypeForStoredPath($path, $fallback);
            }
        };

        // Событие говорит про исходный png, а на диске уже webp — писать надо про webp.
        $this->assertSame('image/webp', $transformer->mimeFor('products/x.webp', 'image/png'));
        $this->assertSame('image/jpeg', $transformer->mimeFor('products/x.jpg'));
        $this->assertSame('video/mp4', $transformer->mimeFor('products/video/x.mp4'));
        // Незнакомое расширение — остаётся то, что прислали.
        $this->assertSame('application/zip', $transformer->mimeFor('docs/x.zip', 'application/zip'));
    }

    #[Test]
    public function non_image_files_are_stored_untouched(): void
    {
        Storage::fake('public');
        config(['images.optimize.enabled' => true]);

        $pdf = '%PDF-1.4 фиктивный документ';
        Http::fake(['*' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/docs/spec.pdf', 'documents');

        $this->assertStringEndsWith('.pdf', $path);
        $this->assertSame($pdf, Storage::disk('public')->get($path));
    }

    #[Test]
    public function svg_is_stored_untouched(): void
    {
        Storage::fake('public');
        config(['images.optimize.enabled' => true]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>';
        Http::fake(['*' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/icons/i.svg', 'categories');

        $this->assertStringEndsWith('.svg', $path);
        $this->assertSame($svg, Storage::disk('public')->get($path));
    }

    #[Test]
    public function original_is_kept_when_optimization_is_disabled(): void
    {
        Storage::fake('public');
        config(['images.optimize.enabled' => false]);

        $source = $this->photoLikePng(600, 600);
        Http::fake(['*' => Http::response($source, 200, ['Content-Type' => 'image/png'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/products/photo.png', 'products');

        $this->assertStringEndsWith('.png', $path);
        $this->assertSame($source, Storage::disk('public')->get($path));
    }

    #[Test]
    public function broken_image_falls_back_to_storing_the_original(): void
    {
        Storage::fake('public');
        config(['images.optimize.enabled' => true]);

        $broken = 'не картинка, хотя Content-Type говорит обратное';
        Http::fake(['*' => Http::response($broken, 200, ['Content-Type' => 'image/png'])]);

        $path = $this->transformer()->fetch('https://admin.example/files/products/broken.png', 'products');

        $this->assertStringEndsWith('.png', $path);
        $this->assertSame($broken, Storage::disk('public')->get($path));
    }
}
