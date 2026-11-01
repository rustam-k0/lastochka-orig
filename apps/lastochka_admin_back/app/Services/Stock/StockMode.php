<?php

namespace App\Services\Stock;

final class StockMode
{
    public const NORMAL = 'normal';
    public const IGNORE = 'ignore';
    public const FIXED = 'fixed';

    /** Допустимые режимы для категории. */
    public static function categoryValues(): array
    {
        return [self::NORMAL, self::IGNORE, self::FIXED];
    }

    /** Допустимые режимы для товара (normal запрещён — пустое поле = «по категории»). */
    public static function productValues(): array
    {
        return [self::IGNORE, self::FIXED];
    }

    /** Режимы, при которых остаток для курьерки/корзины не блокирует. */
    public static function ignoringModes(): array
    {
        return [self::IGNORE, self::FIXED];
    }
}
