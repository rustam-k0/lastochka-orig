<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OrderItem;
use Illuminate\Support\Collection;

final class OrderItemSorter
{
    /**
     * Группирует пары replaced+added подряд: сначала added (товар-замена),
     * сразу за ним связанный replaced (исходный). Item считается парой,
     * если added.origin_product_id == replaced.product_id.
     *
     * Остальные позиции остаются в исходном порядке.
     *
     * @param  iterable<int, OrderItem>  $items
     * @return Collection<int, OrderItem>
     */
    public static function pairReplacementsWithOrigins(iterable $items): Collection
    {
        $items = $items instanceof Collection ? $items->values() : collect($items)->values();

        $pairs = [];
        foreach ($items as $item) {
            if ($item->status !== 'added' || empty($item->origin_product_id)) {
                continue;
            }
            $replaced = $items->first(fn (OrderItem $i): bool => $i->status === 'replaced'
                && (int) $i->product_id === (int) $item->origin_product_id);
            if ($replaced) {
                $pairs[$replaced->id] = $item;
            }
        }

        $addedInPairs = collect($pairs)->map(fn (OrderItem $a): int => (int) $a->id)->all();
        $result = collect();
        $consumed = [];

        foreach ($items as $item) {
            $id = (int) $item->id;
            if (in_array($id, $consumed, true)) {
                continue;
            }

            if (isset($pairs[$id])) {
                $result->push($pairs[$id]);
                $result->push($item);
                $consumed[] = (int) $pairs[$id]->id;
                $consumed[] = $id;
                continue;
            }

            if (in_array($id, $addedInPairs, true)) {
                continue;
            }

            $result->push($item);
            $consumed[] = $id;
        }

        return $result;
    }
}
