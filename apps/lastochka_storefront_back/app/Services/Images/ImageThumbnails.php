<?php

declare(strict_types=1);

namespace App\Services\Images;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Превью картинок витрины для сетки каталога.
 *
 * Превью лежит рядом с картинкой по детерминированному пути
 * {папка}/thumbs/{имя}.webp, отдельной колонки в БД нет: путь превью
 * вычисляется из пути картинки, а наличие проверяется по диску (public —
 * локальный диск, проверка = stat файла). Так превью получают сразу все
 * сущности, которые отдаются через ImageResource, без миграций в 10 таблицах.
 */
final class ImageThumbnails
{
    public static function pathFor(string $path): ?string
    {
        if ($path === '' || self::isExternal($path)) {
            return null;
        }

        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $thumbsDir = (string) config('images.thumbnails.directory', 'thumbs');

        // Превью превью не делаем.
        if (basename($dir) === $thumbsDir) {
            return null;
        }

        return ($dir === '.' ? '' : $dir.'/').$thumbsDir.'/'.$name.'.webp';
    }

    /**
     * Сгенерировать превью для уже сохранённой картинки.
     *
     * Никогда не бросает исключений: превью необязательно, и его сбой
     * (например, нет прав на каталог thumbs у пользователя консьюмера)
     * не должен ронять скачивание самой картинки в трансформерах.
     *
     * @return string|null путь превью, null если превью неприменимо
     *                     (svg, gif, видео, картинка не стала меньше, ошибка)
     */
    public static function generate(string $path, ?string $binary = null): ?string
    {
        try {
            return self::generateOrFail($path, $binary);
        } catch (\Throwable $e) {
            Log::warning('ImageThumbnails: не удалось создать превью', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * То же, что generate(), но ошибки записи пробрасываются: нужно команде
     * бэкфилла, чтобы посчитать сбои и показать их, а не молча пропустить.
     */
    public static function generateOrFail(string $path, ?string $binary = null): ?string
    {
        if (! (bool) config('images.thumbnails.enabled', true)) {
            return null;
        }

        $thumbPath = self::pathFor($path);
        if ($thumbPath === null) {
            return null;
        }

        $disk = Storage::disk('public');

        if ($binary === null) {
            if (! $disk->exists($path)) {
                return null;
            }
            $binary = (string) $disk->get($path);
        }

        $thumb = ImageOptimizer::fromConfig()->thumbnail(
            $binary,
            pathinfo($path, PATHINFO_EXTENSION),
            (int) config('images.thumbnails.width', 300),
            (int) config('images.thumbnails.quality', 80),
        );

        if ($thumb === null) {
            return null;
        }

        $disk->put($thumbPath, $thumb->binary);

        return $thumbPath;
    }

    /**
     * Полный URL превью, а если его нет — URL самой картинки.
     */
    public static function urlFor(string $path): string
    {
        $thumbPath = self::pathFor($path);

        if ($thumbPath !== null && Storage::disk('public')->exists($thumbPath)) {
            return Storage::disk('public')->url($thumbPath);
        }

        return \App\Http\Resources\Api\V1\ImageResource::toFullUrl($path);
    }

    /**
     * Пути картинок вместе с путями их превью — для удаления с диска.
     *
     * @param  iterable<string>  $paths
     * @return list<string>
     */
    public static function withThumbnails(iterable $paths): array
    {
        $result = [];

        foreach ($paths as $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }

            $result[] = $path;

            if (($thumbPath = self::pathFor($path)) !== null) {
                $result[] = $thumbPath;
            }
        }

        return array_values(array_unique($result));
    }

    private static function isExternal(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
