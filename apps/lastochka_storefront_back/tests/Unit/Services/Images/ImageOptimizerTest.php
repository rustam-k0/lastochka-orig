<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Images;

use App\Services\Images\ImageOptimizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ImageOptimizerTest extends TestCase
{
    private function optimizer(int $quality = 85, int $maxWidth = 1200, bool $enabled = true): ImageOptimizer
    {
        return new ImageOptimizer($enabled, $quality, $maxWidth, ['jpg', 'jpeg', 'png', 'bmp']);
    }

    private function pngBinary(int $width, int $height, bool $withAlpha = false): string
    {
        $im = imagecreatetruecolor($width, $height);
        if ($withAlpha) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 0, 0, 127));
        } else {
            // Градиент с шумом — приближение к реальному фото. Синтетические
            // полоски или заливка PNG жмёт лучше, чем WebP, и оптимизатор
            // справедливо от них отказывается: на таком фикстура врёт.
            mt_srand(42);
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    imagesetpixel($im, $x, $y, imagecolorallocate(
                        $im,
                        min(255, (int) ($x * 255 / max(1, $width)) + mt_rand(0, 30)),
                        min(255, (int) ($y * 255 / max(1, $height)) + mt_rand(0, 30)),
                        mt_rand(80, 160),
                    ));
                }
            }
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    #[Test]
    public function it_converts_a_png_to_webp(): void
    {
        $result = $this->optimizer()->optimize($this->pngBinary(400, 400), 'png');

        $this->assertNotNull($result);
        $this->assertSame('webp', $result->extension);
        $this->assertSame('image/webp', $result->mimeType);
        $this->assertNotSame('', $result->binary);

        $info = getimagesizefromstring($result->binary);
        $this->assertIsArray($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
    }

    #[Test]
    public function it_never_upscales_images_narrower_than_the_limit(): void
    {
        $result = $this->optimizer(maxWidth: 1200)->optimize($this->pngBinary(600, 400), 'png');

        $this->assertNotNull($result);
        $info = getimagesizefromstring($result->binary);
        $this->assertSame(600, $info[0], 'Ширина не должна увеличиваться до max_width');
        $this->assertSame(400, $info[1]);
    }

    #[Test]
    public function it_caps_width_and_keeps_aspect_ratio(): void
    {
        $result = $this->optimizer(maxWidth: 500)->optimize($this->pngBinary(1000, 800), 'png');

        $this->assertNotNull($result);
        $info = getimagesizefromstring($result->binary);
        $this->assertSame(500, $info[0]);
        $this->assertSame(400, $info[1], 'Пропорции должны сохраняться');
    }

    #[Test]
    public function it_preserves_transparency(): void
    {
        $result = $this->optimizer()->optimize($this->pngBinary(200, 200, withAlpha: true), 'png');

        $this->assertNotNull($result);

        $im = imagecreatefromstring($result->binary);
        $this->assertNotFalse($im);
        $alpha = (imagecolorat($im, 10, 10) >> 24) & 0x7F;
        imagedestroy($im);

        $this->assertGreaterThan(0, $alpha, 'Альфа-канал должен сохраняться');
    }

    #[Test]
    public function it_makes_the_file_smaller(): void
    {
        $source = $this->pngBinary(500, 500);
        $result = $this->optimizer()->optimize($source, 'png');

        $this->assertNotNull($result);
        $this->assertLessThan(strlen($source), strlen($result->binary));
    }

    #[Test]
    public function it_skips_formats_that_should_not_be_converted(): void
    {
        foreach (['svg', 'gif', 'webp', 'mp4', 'pdf'] as $extension) {
            $this->assertFalse(
                $this->optimizer()->handles($extension),
                "Расширение {$extension} не должно конвертироваться",
            );
        }

        foreach (['jpg', 'jpeg', 'png', 'PNG', 'bmp'] as $extension) {
            $this->assertTrue($this->optimizer()->handles($extension));
        }
    }

    #[Test]
    public function it_returns_null_when_disabled(): void
    {
        $optimizer = $this->optimizer(enabled: false);

        $this->assertFalse($optimizer->handles('png'));
        $this->assertNull($optimizer->optimize($this->pngBinary(400, 400), 'png'));
    }

    #[Test]
    public function it_returns_null_for_unreadable_binary_instead_of_throwing(): void
    {
        $this->assertNull($this->optimizer()->optimize('это вообще не картинка', 'png'));
    }

    #[Test]
    public function it_gives_up_when_the_result_is_not_smaller(): void
    {
        // Крошечная картинка: накладные расходы контейнера WebP превышают выигрыш.
        $source = $this->pngBinary(4, 4);
        $result = $this->optimizer()->optimize($source, 'png');

        if ($result !== null) {
            $this->assertLessThan(strlen($source), strlen($result->binary));
        } else {
            $this->assertTrue(true, 'Оптимизатор корректно отказался ухудшать файл');
        }
    }
}
