<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Целое число из события 1С (MID-306). Та же семантика, что App\Support\OneCInt в admin_back.
 *
 * Принимаются int, целый float (7.0) и строка из цифр; значение должно быть в [min, max].
 * Результат [valid, value]: [true, int] — значение; [true, null] — пустое (null или пустая строка);
 * [false, null] — нераспознано или вне диапазона.
 */
final class OneCInt
{
    /**
     * @return array{0: bool, 1: int|null}
     */
    public static function parse(mixed $raw, int $min, ?int $max = null): array
    {
        if ($raw === null) {
            return [true, null];
        }

        if (is_float($raw)) {
            $raw = is_finite($raw) && floor($raw) === $raw && abs($raw) <= PHP_INT_MAX ? (int) $raw : null;
        } elseif (is_string($raw)) {
            $raw = trim($raw);

            if ($raw === '') {
                return [true, null];
            }

            $raw = preg_match('/^-?\d{1,18}$/', $raw) === 1 ? (int) $raw : null;
        }

        if (! is_int($raw) || $raw < $min || ($max !== null && $raw > $max)) {
            return [false, null];
        }

        return [true, $raw];
    }
}
