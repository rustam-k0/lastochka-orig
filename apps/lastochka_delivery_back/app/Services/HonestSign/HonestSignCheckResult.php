<?php

namespace App\Services\HonestSign;

/**
 * Результат проверки кода маркировки в 1С (CheckCIS).
 *
 * available=false — ответа по существу нет (не настроено, таймаут,
 * авторизация, 5xx): сборщику показываем «повторите», код не сохраняем.
 * available=true, allowed=false — 1С ответила отказом, message — причина.
 */
final class HonestSignCheckResult
{
  public function __construct(
    public readonly bool $allowed,
    public readonly bool $available,
    public readonly ?string $message = null,
  ) {}

  public static function allowed(?string $message = null): self
  {
    return new self(allowed: true, available: true, message: $message);
  }

  public static function rejected(?string $message = null): self
  {
    return new self(allowed: false, available: true, message: $message);
  }

  public static function unavailable(?string $message = null): self
  {
    return new self(allowed: false, available: false, message: $message);
  }
}
