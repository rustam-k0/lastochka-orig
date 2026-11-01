<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка статичной страницы.
 * Содержимое отдаётся распакованным EditorJS-документом,
 * чтобы клиенту не приходилось парсить строку.
 *
 * @mixin \App\Models\Page
 */
final class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'title' => (string) $this->title,
            /** @var array<string, mixed> EditorJS-документ: ключ blocks с массивом блоков */
            'content' => $this->contentDocument(),
            'metaTitle' => $this->meta_title,
            'metaDescription' => $this->meta_description,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
