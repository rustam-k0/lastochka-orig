<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Генерация ЧПУ-слага товара из названия.
 *
 * Слаг живёт только в storefront: 1С и admin_back его не присылают,
 * поэтому источник — title на момент создания товара. При переименовании
 * товара слаг НЕ перегенерируется — ссылки клиентов остаются рабочими.
 */
final class ProductSlugGenerator
{
    /**
     * Запас под суффикс уникализации `-N` в колонке slug (255).
     */
    private const MAX_BASE_LENGTH = 240;

    /**
     * Слаг из названия с уникализацией. Пустой title → null.
     *
     * @param  int|null  $ignoreProductId  не считать коллизией собственный слаг товара
     */
    public function generate(?string $title, ?int $ignoreProductId = null): ?string
    {
        $base = Str::slug((string) $title, '-', 'ru');

        if ($base === '') {
            return null;
        }

        $base = mb_substr($base, 0, self::MAX_BASE_LENGTH);

        $slug = $base;
        $suffix = 2;

        while ($this->taken($slug, $ignoreProductId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function taken(string $slug, ?int $ignoreProductId): bool
    {
        return Product::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreProductId !== null, fn ($q) => $q->where('id', '!=', $ignoreProductId))
            ->exists();
    }
}
