<?php

namespace App\Support;

/**
 * Нормализация кода/артикула товара 1С.
 *
 * 1С отдаёт числовые коды отформатированными: разряды разделены НЕРАЗРЫВНЫМ
 * пробелом (U+00A0) — «172 484», «27 386». В таком виде значение и попадало в
 * products.code/articul (обычный trim() NBSP не убирает), а на ценнике сборщик
 * видит те же цифры и вводит их слитно: «172484». Точное сравнение не совпадало,
 * и товар «не находился по артикулу».
 *
 * Правило: у чисто числовых значений разделители разрядов убираем целиком,
 * у остальных (буквенные артикулы вида «ФУТБОЛКА XL») трогаем только края —
 * внутренний пробел там несёт смысл.
 */
final class ProductCode
{
  /** Пробельные символы, которыми 1С разделяет разряды числа. */
  private const SPACE_CLASS = '[\s\x{00A0}\x{2007}\x{202F}\x{2009}\x{205F}\x{3000}]';

  /** Длина PLU на ценнике и в весовом штрихкоде: «00030». */
  private const PLU_LENGTH = 5;

  /** Нормализованное значение кода: «172 484» → «172484», « ABC 1 » → «ABC 1». */
  public static function normalize(?string $value): ?string
  {
    if ($value === null) {
      return null;
    }

    $trimmed = preg_replace('/^' . self::SPACE_CLASS . '+|' . self::SPACE_CLASS . '+$/u', '', $value);

    if ($trimmed === '' || $trimmed === null) {
      return '';
    }

    $compact = preg_replace('/' . self::SPACE_CLASS . '+/u', '', $trimmed);

    return preg_match('/^\d+$/', (string) $compact) === 1 ? $compact : $trimmed;
  }

  /**
   * Варианты числового кода/PLU с точностью до ведущих нулей — для whereIn.
   *
   * На ценнике 1С печатает PLU весового товара дополненным до 5 знаков
   * («PLU: 00030»), а в базе значение лежит без нулей («30») или, наоборот,
   * уже дополненным. Варианты: без ведущих нулей и с нулями до
   * max(5, длина ввода). Нечисловой или пустой ввод — пустой массив: к
   * буквенным артикулам толерантность к нулям не применяется.
   *
   * @return string[]
   */
  public static function zeroPaddedCandidates(?string $value): array
  {
    $normalized = self::normalize($value);

    if ($normalized === null || $normalized === '' || ! ctype_digit($normalized)) {
      return [];
    }

    $stripped = ltrim($normalized, '0');
    $stripped = $stripped === '' ? '0' : $stripped;

    $candidates = [];
    for ($length = strlen($stripped); $length <= max(self::PLU_LENGTH, strlen($normalized)); $length++) {
      $candidates[] = str_pad($stripped, $length, '0', STR_PAD_LEFT);
    }

    return $candidates;
  }

  /**
   * SQL-выражение, приводящее колонку к нормализованному виду — для поиска по
   * данным, которые ещё не почищены миграцией (или пришли из старого источника).
   */
  public static function normalizedColumnSql(string $column): string
  {
    return sprintf(
      "REPLACE(REPLACE(REPLACE(%s, CHAR(0xC2, 0xA0 USING utf8mb4), ''), ' ', ''), CHAR(9), '')",
      $column
    );
  }
}
