<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Services\Images\ImageThumbnails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Универсальный ресурс для изображений.
 *
 * Использование:
 * - Для моделей (ProductImage, StoryImage): new ImageResource($model)
 * - Для inline (image_path): ImageResource::fromPath($this->image_path, $this->id)
 *
 * thumbPath — превью ~300 px для сетки каталога (см. ImageThumbnails);
 * если превью нет, совпадает с path. Для видео и других не-картинок не отдаётся.
 *
 * Всегда возвращает полный URL:
 * - Если путь уже полный (http/https) — возвращает как есть
 * - Если путь относительный — добавляет базовый URL Storage
 */
final class ImageResource extends JsonResource
{
    public static function fromPath(?string $path, ?int $id = null): ?self
    {
        if ($path === null) {
            return null;
        }

        return new self(['id' => $id, 'path' => $path]);
    }

    public function toArray(Request $request): array
    {
        if ($this->resource instanceof Model) {
            $data = [
                'id' => (int) $this->resource->id,
                'path' => self::toFullUrl((string) $this->resource->path),
            ];

            // Добавляем type только если поле существует и не 'image' (обратная совместимость)
            if (isset($this->resource->type) && $this->resource->type !== 'image') {
                $data['type'] = $this->resource->type;
                $data['mimeType'] = $this->resource->mime_type;
            } else {
                $data['thumbPath'] = ImageThumbnails::urlFor((string) $this->resource->path);
            }

            return $data;
        }

        return [
            'id' => isset($this->resource['id']) ? (int) $this->resource['id'] : null,
            'path' => self::toFullUrl((string) $this->resource['path']),
            'thumbPath' => ImageThumbnails::urlFor((string) $this->resource['path']),
        ];
    }

    /**
     * Преобразует путь в полный URL.
     * Если путь уже полный (http/https) — возвращает как есть.
     * Если относительный — добавляет базовый URL Storage.
     */
    public static function toFullUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
