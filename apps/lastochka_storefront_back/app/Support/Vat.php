<?php

declare(strict_types=1);

namespace App\Support;

/**
 * НДС по кодам ставок ЮKassa.
 *
 * Коды: 1 — без НДС, 2 — 0%, 3 — 10%, 4 — 20%, 5 — 10/110, 6 — 20/120,
 * 11 — 22%, 12 — 22/122 (базовая ставка РФ с 01.01.2026, ФЗ от 28.11.2025
 * №425-ФЗ; 4 и 6 остаются для переходных и исторических операций).
 * НДС в рознице включён в цену («в том числе»), поэтому сумма налога
 * извлекается из gross: gross × rate / (100 + rate).
 */
final class Vat
{
    /**
     * Ставка НДС в процентах по коду ЮKassa.
     *
     * Коды 11/12 присылает `lastochka_1c_parser::normalizeNdsToVatCode`
     * («22» и «22/122» из CommerceML). Без них ставка схлопывалась в 0%
     * и товар уходил в чек как «без НДС».
     */
    public static function rateFromCode(int $vatCode): int
    {
        return match ($vatCode) {
            3, 5 => 10,
            4, 6 => 20,
            11, 12 => 22,
            default => 0,
        };
    }

    /**
     * НДС, включённый в сумму («в том числе»): gross × rate / (100 + rate).
     */
    public static function includedVat(float $gross, int $rate): float
    {
        if ($rate <= 0) {
            return 0.0;
        }

        return round($gross * $rate / (100 + $rate), 2);
    }

    /**
     * Код ставки по умолчанию для товаров без products.vat_rate.
     */
    public static function defaultCode(): int
    {
        return (int) config('services.yookassa.vat_code', 4);
    }
}
