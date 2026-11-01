<?php

namespace App\Services\ProductContent;

/** Результат импорта одной строки файла. */
class ImportRowResult
{
  /**
   * @param string[] $updated колонки, записанные в БД
   * @param string[] $skipped колонки, пропущенные как уже заполненные
   * @param array<string, string> $errors колонка => причина ошибки
   */
  public function __construct(
    public readonly ?int $productId,
    public readonly int $rowNumber,
    public array $updated = [],
    public array $skipped = [],
    public array $errors = [],
  ) {
  }

  public function changed(): bool
  {
    return $this->updated !== [];
  }
}
