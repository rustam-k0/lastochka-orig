<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Булево значение из события 1С (MID-306). Та же семантика, что App\Support\OneCBool в storefront_back.
 *
 * Распознаются true/false, 1/0, «true»/«false»/«1»/«0» (регистр и пробелы не важны).
 * Результат [valid, value]: [true, bool] — значение; [true, null] — пустое (null или пустая строка);
 * [false, null] — нераспознано.
 */
final class OneCBool
{
  /**
   * @return array{0: bool, 1: bool|null}
   */
  public static function parse(mixed $raw): array
  {
    if ($raw === null) {
      return [true, null];
    }

    if (is_bool($raw)) {
      return [true, $raw];
    }

    if (is_int($raw)) {
      return in_array($raw, [0, 1], true) ? [true, $raw === 1] : [false, null];
    }

    if (!is_string($raw)) {
      return [false, null];
    }

    $value = mb_strtolower(trim($raw));

    return match ($value) {
      '' => [true, null],
      'true', '1' => [true, true],
      'false', '0' => [true, false],
      default => [false, null],
    };
  }
}
