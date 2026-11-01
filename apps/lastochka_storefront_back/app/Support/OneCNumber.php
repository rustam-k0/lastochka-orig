<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Число из события 1С (MID-306): число или строка «12,5», «1 234,5».
 * Та же семантика, что App\Support\OneCNumber в admin_back.
 */
final class OneCNumber
{
    /**
     * @return float|false|null число; null — пустое значение (очистить); false — нераспознано
     */
    public static function parse(mixed $raw): float|false|null
    {
        if ($raw === null) {
            return null;
        }

        if (is_bool($raw) || is_array($raw) || is_object($raw)) {
            return false;
        }

        if (is_int($raw) || is_float($raw)) {
            return is_finite((float) $raw) ? (float) $raw : false;
        }

        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $raw));

        if ($value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : false;
    }
}
