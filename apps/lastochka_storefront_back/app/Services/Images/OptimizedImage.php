<?php

declare(strict_types=1);

namespace App\Services\Images;

/**
 * Результат оптимизации: готовые байты и метаданные для сохранения.
 */
final readonly class OptimizedImage
{
    public function __construct(
        public string $binary,
        public string $extension,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {}

    public function size(): int
    {
        return strlen($this->binary);
    }
}
