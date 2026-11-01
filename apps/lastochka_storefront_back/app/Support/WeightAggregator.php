<?php

declare(strict_types=1);

namespace App\Support;

final class WeightAggregator
{
    /**
     * Сумма веса в граммах по коллекции элементов с методом grams(): ?int.
     * Возвращает null, если ни один элемент не дал значения — это нужно,
     * чтобы UI скрывал строку «вес посылки», когда данных нет.
     *
     * @param  iterable<object>  $items
     */
    public static function sumGrams(iterable $items): ?int
    {
        $total = 0;
        $hasAny = false;

        foreach ($items as $item) {
            if (! method_exists($item, 'grams')) {
                continue;
            }

            $grams = $item->grams();
            if ($grams === null) {
                continue;
            }

            $hasAny = true;
            $total += (int) $grams;
        }

        return $hasAny ? $total : null;
    }

    /**
     * Граммы → килограммы, округлённые до 3 знаков. null проходит насквозь.
     */
    public static function toKg(?int $grams): ?float
    {
        return $grams === null ? null : round($grams / 1000, 3);
    }
}
