<?php

declare(strict_types=1);

namespace App\Services\Images;

use Illuminate\Support\Facades\Log;

/**
 * Перекодирует растровые картинки витрины в WebP с ограничением по ширине.
 *
 * Сознательные решения:
 * - max_width это ПОТОЛОК, а не целевой размер: картинки уже него не растягиваются.
 *   Апскейл делает файл больше, а картинку мягче — ровно противоположно задаче.
 * - Альфа-канал сохраняется: часть PNG это вырезанные товары на прозрачном фоне.
 * - Любой сбой возвращает null, а не исключение. Вызывающий код тогда сохраняет
 *   оригинал как раньше: не оптимизировать картинку допустимо, потерять её — нет.
 */
final class ImageOptimizer
{
    /** @param  list<string>  $sourceExtensions */
    public function __construct(
        private readonly bool $enabled,
        private readonly int $quality,
        private readonly int $maxWidth,
        private readonly array $sourceExtensions,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $cfg */
        $cfg = config('images.optimize', []);

        return new self(
            (bool) ($cfg['enabled'] ?? false),
            (int) ($cfg['quality'] ?? 85),
            (int) ($cfg['max_width'] ?? 1200),
            array_map('strval', (array) ($cfg['source_extensions'] ?? [])),
        );
    }

    /**
     * Стоит ли вообще трогать файл с таким расширением.
     */
    public function handles(string $extension): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return in_array(mb_strtolower($extension), $this->sourceExtensions, true);
    }

    public function targetExtension(): string
    {
        return 'webp';
    }

    /**
     * Вернуть оптимизированную копию либо null, если оптимизация неприменима,
     * не удалась или не дала выигрыша.
     */
    public function optimize(string $binary, string $extension): ?OptimizedImage
    {
        if (! $this->handles($extension)) {
            return null;
        }

        return $this->encode($binary, $extension, $this->maxWidth, $this->quality);
    }

    /**
     * Превью для сетки каталога: WebP шириной не больше $width.
     *
     * В отличие от optimize() принимает и WebP-источник: на диске витрины
     * картинки уже лежат в WebP после оптимизации, превью делается из них.
     * Рубильник images.optimize.enabled на превью не влияет, у них свой.
     */
    public function thumbnail(string $binary, string $extension, int $width, int $quality): ?OptimizedImage
    {
        $allowed = [...$this->sourceExtensions, $this->targetExtension()];

        if (! in_array(mb_strtolower($extension), $allowed, true)) {
            return null;
        }

        return $this->encode($binary, $extension, $width, $quality);
    }

    private function encode(string $binary, string $extension, int $maxWidth, int $quality): ?OptimizedImage
    {
        if (! function_exists('imagewebp')) {
            Log::warning('ImageOptimizer: сборка PHP без поддержки WebP, картинки сохраняются как есть');

            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width < 1 || $height < 1) {
                return null;
            }

            // Только уменьшение. Апскейла не делаем никогда.
            $targetWidth = min($maxWidth, $width);
            $targetHeight = (int) max(1, round($height * $targetWidth / $width));

            $canvas = $targetWidth === $width
                ? $source
                : $this->scale($source, $targetWidth, $targetHeight);

            if ($canvas === false) {
                return null;
            }

            try {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);

                ob_start();
                $ok = imagewebp($canvas, null, $quality);
                $encoded = (string) ob_get_clean();

                if ($ok === false || $encoded === '') {
                    return null;
                }

                // Перекодирование, которое сделало файл больше, лишено смысла.
                if (strlen($encoded) >= strlen($binary)) {
                    return null;
                }

                return new OptimizedImage(
                    binary: $encoded,
                    extension: $this->targetExtension(),
                    mimeType: 'image/webp',
                    width: $targetWidth,
                    height: $targetHeight,
                );
            } finally {
                if ($canvas !== $source) {
                    imagedestroy($canvas);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ImageOptimizer: не удалось перекодировать картинку', [
                'extension' => $extension,
                'bytes' => strlen($binary),
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Уменьшить картинку, выбрав лучший доступный режим интерполяции.
     *
     * Набор режимов зависит от сборки GD: IMG_BICUBIC есть в GD на серверах
     * проекта, но отсутствует в некоторых сборках (например, в homebrew-версии
     * GD 2.3.3 imagescale с ним возвращает false). Поэтому идём по списку
     * от лучшего к гарантированно доступному, а не полагаемся на один режим.
     *
     * @return \GdImage|false
     */
    private function scale(\GdImage $source, int $width, int $height)
    {
        foreach ([IMG_BICUBIC, IMG_BILINEAR_FIXED, IMG_NEAREST_NEIGHBOUR] as $mode) {
            $scaled = @imagescale($source, $width, $height, $mode);

            if ($scaled !== false) {
                return $scaled;
            }
        }

        return false;
    }
}
