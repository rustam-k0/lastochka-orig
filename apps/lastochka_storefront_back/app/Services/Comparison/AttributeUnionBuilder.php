<?php

declare(strict_types=1);

namespace App\Services\Comparison;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Собирает таблицу атрибутов для сравнения товаров одной группы.
 *
 * UNION уникальных атрибутов всех товаров по `code`. При конфликте `name`/`position`
 * приоритет — у первого товара по `created_at` (детерминированно).
 *
 * @phpstan-type AttributeRow array{code: string, name: string, position: int}
 */
final class AttributeUnionBuilder
{
    /**
     * @param  Collection<int, Product>  $products
     * @return list<AttributeRow>
     */
    public function build(Collection $products): array
    {
        /** @var array<string, AttributeRow> $merged */
        $merged = [];

        $orderedProducts = $products->sortBy(
            fn (Product $product): int => $product->created_at?->getTimestamp() ?? 0
        );

        foreach ($orderedProducts as $product) {
            foreach ($product->attributes as $attribute) {
                $code = (string) $attribute->code;

                if ($code === '' || isset($merged[$code])) {
                    continue;
                }

                $merged[$code] = [
                    'code' => $code,
                    'name' => (string) ($attribute->name ?? $code),
                    'position' => (int) ($attribute->position ?? 0),
                ];
            }
        }

        $result = array_values($merged);

        usort(
            $result,
            static fn (array $a, array $b): int => [$a['position'], $a['code']] <=> [$b['position'], $b['code']]
        );

        return $result;
    }
}
