<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Страница в списке статичных страниц.
 * Содержимое намеренно не отдаётся: список нужен для футера сайта
 * и меню приложения, текст запрашивается отдельно по коду страницы.
 *
 * @mixin \App\Models\Page
 */
final class PageListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'title' => (string) $this->title,
            'position' => $this->position !== null ? (int) $this->position : null,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
