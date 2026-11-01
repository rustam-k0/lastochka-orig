<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ставка НДС из 1С (процент или строка) → код ставки ЮKassa (MID-305).
 *
 * 1С присылает «22», «22%», «22.0», «Без НДС», «22/122». Коды: 1 без НДС,
 * 2 — 0%, 3 — 10%, 4 — 20% (и старые 18%), 5 — 10/110, 6 — 20/120 (и 18/118),
 * 11 — 22%, 12 — 22/122 (базовая ставка с 01.01.2026, ФЗ №425-ФЗ).
 * Ставки 5% и 7% не поддерживаются: витрина не знает их кодов.
 *
 * @return int|false|null код; null — очистить (пустое значение); false — нераспознано
 */
final class NdsRate
{
  private const FRACTIONS = [
    '22/122' => 12,
    '20/120' => 6,
    '18/118' => 6,
    '10/110' => 5,
  ];

  private const RATES = [
    [0.0, 2],
    [10.0, 3],
    [18.0, 4],
    [20.0, 4],
    [22.0, 11],
  ];

  public static function toVatCode(mixed $raw): int|false|null
  {
    if ($raw === null) {
      return null;
    }

    if (is_bool($raw) || is_array($raw) || is_object($raw)) {
      return false;
    }

    $value = mb_strtolower(trim((string) $raw));
    if ($value === '') {
      return null;
    }

    if (str_contains($value, 'без') || str_contains($value, 'не облага')) {
      return 1;
    }

    $value = (string) preg_replace('/[\s\x{00A0}%]+/u', '', $value);
    $value = str_replace(',', '.', $value);

    if (array_key_exists($value, self::FRACTIONS)) {
      return self::FRACTIONS[$value];
    }

    if (!is_numeric($value)) {
      return false;
    }

    $rate = (float) $value;
    foreach (self::RATES as [$knownRate, $code]) {
      if (abs($rate - $knownRate) < 0.001) {
        return $code;
      }
    }

    return false;
  }
}
