<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Телефон сотрудника из 1С (MID-306) в формате входа приложений сборщика и курьера.
 *
 * 11 цифр на 7/8 или 10 цифр → +7XXXXXXXXXX; иначе null (не распознан).
 */
final class EmployeePhone
{
  public static function normalize(mixed $raw): ?string
  {
    if (!is_string($raw) && !is_int($raw)) {
      return null;
    }

    $digits = (string) preg_replace('/\D+/', '', (string) $raw);
    $length = mb_strlen($digits);

    if ($length === 10) {
      return '+7' . $digits;
    }

    if ($length === 11 && in_array(mb_substr($digits, 0, 1), ['7', '8'], true)) {
      return '+7' . mb_substr($digits, 1);
    }

    return null;
  }
}
