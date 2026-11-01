<?php

declare(strict_types=1);

namespace Tests\Feature\Images;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OptimizeStoredImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'images.optimize.enabled' => true,
            'images.optimize.quality' => 85,
            'images.optimize.max_width' => 300,
            'images.optimize.source_extensions' => ['jpg', 'jpeg', 'png', 'bmp'],
            'images.optimize.backfill_targets' => ['product_images' => ['path']],
        ]);
    }

    private function photoPng(int $width = 500, int $height = 500): string
    {
        $im = imagecreatetruecolor($width, $height);
        mt_srand(11);
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

    private function makeImage(string $path, ?string $binary = null): ProductImage
    {
        Storage::disk('public')->put($path, $binary ?? $this->photoPng());

        return ProductImage::create([
            'product_id' => Product::factory()->create()->id,
            'path' => $path,
            'source_url' => 'https://admin.example/files/'.basename($path),
            'type' => 'image',
            'mime_type' => 'image/png',
            'position' => 0,
        ]);
    }

    #[Test]
    public function it_converts_images_and_keeps_the_original_file(): void
    {
        $image = $this->makeImage('products/photo-one.png');

        $this->artisan('images:optimize')->assertSuccessful();

        $image->refresh();
        $this->assertSame('products/photo-one.webp', $image->path);
        Storage::disk('public')->assertExists('products/photo-one.webp');
        Storage::disk('public')->assertExists('products/photo-one.png');
    }

    #[Test]
    public function it_does_not_touch_source_url(): void
    {
        $image = $this->makeImage('products/photo-two.png');
        $before = $image->source_url;

        $this->artisan('images:optimize')->assertSuccessful();

        $this->assertSame($before, $image->refresh()->source_url);
    }

    #[Test]
    public function dry_run_changes_nothing(): void
    {
        $image = $this->makeImage('products/photo-three.png');

        $this->artisan('images:optimize', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('products/photo-three.png', $image->refresh()->path);
        Storage::disk('public')->assertMissing('products/photo-three.webp');
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $image = $this->makeImage('products/photo-four.png');

        $this->artisan('images:optimize')->assertSuccessful();
        $afterFirst = $image->refresh()->path;

        $this->artisan('images:optimize')->assertSuccessful();

        $this->assertSame($afterFirst, $image->refresh()->path);
    }

    #[Test]
    public function it_respects_the_limit(): void
    {
        $this->makeImage('products/a.png');
        $this->makeImage('products/b.png');
        $this->makeImage('products/c.png');

        $this->artisan('images:optimize', ['--limit' => 1])->assertSuccessful();

        $converted = DB::table('product_images')->where('path', 'like', '%.webp')->count();
        $this->assertSame(1, $converted);
    }

    #[Test]
    public function it_skips_rows_whose_file_is_missing(): void
    {
        $image = ProductImage::create([
            'product_id' => Product::factory()->create()->id,
            'path' => 'products/ghost.png',
            'source_url' => 'https://admin.example/files/ghost.png',
            'type' => 'image',
            'mime_type' => 'image/png',
            'position' => 0,
        ]);

        $this->artisan('images:optimize')->assertSuccessful();

        $this->assertSame('products/ghost.png', $image->refresh()->path);
    }

    #[Test]
    public function it_skips_svg(): void
    {
        $image = $this->makeImage('products/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->artisan('images:optimize')->assertSuccessful();

        $this->assertSame('products/icon.svg', $image->refresh()->path);
    }

    #[Test]
    public function it_updates_mime_type_and_restores_it_on_rollback(): void
    {
        $image = $this->makeImage('products/mime.png');
        $this->assertSame('image/png', $image->mime_type);

        $this->artisan('images:optimize')->assertSuccessful();
        $this->assertSame('image/webp', $image->refresh()->mime_type);

        $manifest = collect(Storage::disk('local')->files('image-optimize'))->first();
        $this->artisan('images:optimize', ['--rollback' => Storage::disk('local')->path($manifest)])
            ->assertSuccessful();

        $this->assertSame('image/png', $image->refresh()->mime_type);
    }

    #[Test]
    public function rollback_restores_previous_paths(): void
    {
        $image = $this->makeImage('products/rollback-me.png');

        $this->artisan('images:optimize')->assertSuccessful();
        $this->assertSame('products/rollback-me.webp', $image->refresh()->path);

        $manifest = collect(Storage::disk('local')->files('image-optimize'))->first();
        $this->assertNotNull($manifest, 'Манифест отката должен быть создан');

        $this->artisan('images:optimize', ['--rollback' => Storage::disk('local')->path($manifest)])
            ->assertSuccessful();

        $this->assertSame('products/rollback-me.png', $image->refresh()->path);
    }

    #[Test]
    public function rollback_refuses_when_the_original_is_gone(): void
    {
        $image = $this->makeImage('products/no-original.png');

        $this->artisan('images:optimize')->assertSuccessful();
        Storage::disk('public')->delete('products/no-original.png');

        $manifest = collect(Storage::disk('local')->files('image-optimize'))->first();

        $this->artisan('images:optimize', ['--rollback' => Storage::disk('local')->path($manifest)])
            ->assertSuccessful();

        // Путь остался на webp: вернуть его на отсутствующий файл было бы хуже.
        $this->assertSame('products/no-original.webp', $image->refresh()->path);
    }
}
