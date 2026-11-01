<?php

namespace App\Services\ProductContent;

use App\Events\ProductUpdated;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Обратный импорт контента товаров из файла парсера (LASTOCHKI-JAMI-12027).
 *
 * Пишет ТОЛЬКО контентные поля (описание, meta_*, 10 атрибутов, фото) и только
 * в пустые значения (если не передан $overwrite). Никогда не трогает
 * status_id / publish_after_unlock / articul / title / штрих-коды / цены /
 * остатки / категории — регресс LASTOCHKI-JAMI-11983.
 */
class ProductContentImportService
{
  private ?Collection $attributesByCode = null;

  public function __construct(private readonly ProductContentPhotoImporter $photos)
  {
  }

  /**
   * @param array<string, string|null> $row имя колонки => значение ячейки
   */
  public function importRow(array $row, int $rowNumber, bool $overwrite = false, bool $dryRun = false): ImportRowResult
  {
    $idRaw = trim((string) ($row[ProductContentSchema::COL_ID] ?? ''));

    if (!preg_match('/^\d+$/', $idRaw)) {
      return new ImportRowResult(null, $rowNumber, errors: [
        ProductContentSchema::COL_ID => 'id пустой или не число',
      ]);
    }

    $product = Product::find((int) $idRaw);

    if (!$product) {
      return new ImportRowResult((int) $idRaw, $rowNumber, errors: [
        ProductContentSchema::COL_ID => 'товар не найден или удалён',
      ]);
    }

    $result = new ImportRowResult($product->id, $rowNumber);

    $write = function () use ($row, $product, $overwrite, $dryRun, $result) {
      $this->importProductFields($row, $product, $overwrite, $dryRun, $result);
      $this->importAttributes($row, $product, $overwrite, $dryRun, $result);
    };

    $dryRun ? $write() : DB::transaction($write);

    $this->importPhotos($row, $product, $dryRun, $result);

    if (!$dryRun && $result->changed()) {
      event(new ProductUpdated($product->fresh()));
    }

    return $result;
  }

  private function importProductFields(array $row, Product $product, bool $overwrite, bool $dryRun, ImportRowResult $result): void
  {
    $dirty = false;

    foreach (ProductContentSchema::PRODUCT_FIELDS as $column => $field) {
      if (!array_key_exists($column, $row)) {
        continue; // колонки нет в файле
      }

      $value = trim((string) $row[$column]);

      if (ProductContentSchema::isEmptyValue($value)) {
        continue; // пустая ячейка (и заглушка '0') ничего не стирает и не пишется
      }

      if ($field === 'meta_title' && mb_strlen($value) > ProductContentSchema::STRING_MAX_LENGTH) {
        $result->errors[$column] = 'длиннее 255 символов';
        continue;
      }

      if (!ProductContentSchema::isEmptyValue($product->{$field}) && !$overwrite) {
        $result->skipped[] = $column;
        continue;
      }

      if (!$dryRun) {
        $product->{$field} = $value;
        $dirty = true;
      }

      $result->updated[] = $column;
    }

    if ($dirty) {
      $product->save();
    }
  }

  private function importAttributes(array $row, Product $product, bool $overwrite, bool $dryRun, ImportRowResult $result): void
  {
    foreach (ProductContentSchema::ATTRIBUTE_COLUMNS as $column => $code) {
      if (!array_key_exists($column, $row)) {
        continue;
      }

      $value = trim((string) $row[$column]);

      if (ProductContentSchema::isEmptyValue($value)) {
        continue; // пустая ячейка (и заглушка '0') ничего не стирает и не пишется
      }

      $error = $this->validateAttributeValue($code, $value);
      if ($error !== null) {
        $result->errors[$column] = $error;
        continue;
      }

      $attribute = $this->attribute($code);
      if (!$attribute) {
        $result->errors[$column] = "атрибут «{$code}» не найден в системе";
        continue;
      }

      $existing = ProductAttribute::query()
        ->where('product_id', $product->id)
        ->where('attribute_id', $attribute->id)
        ->first();

      $currentValue = $existing?->string_value ?? $existing?->text_value;

      if (!ProductContentSchema::isEmptyValue($currentValue) && !$overwrite) {
        $result->skipped[] = $column;
        continue;
      }

      if (!$dryRun) {
        $this->writeAttribute($product, $attribute, $existing, $value);
      }

      $result->updated[] = $column;
    }
  }

  /** @return string|null причина ошибки или null если значение валидно */
  private function validateAttributeValue(string $code, string $value): ?string
  {
    if (mb_strlen($value) > ProductContentSchema::STRING_MAX_LENGTH && $code !== 'composition') {
      return 'длиннее 255 символов';
    }

    if (in_array($code, ProductContentSchema::NUMERIC_ATTRIBUTE_CODES, true)
      && !preg_match('/^\d+([.,]\d+)?(\s*\D{0,20})?$/u', $value)) {
      return 'ожидается число (допустим суффикс единицы, напр. «8,6 г»)';
    }

    return null;
  }

  private function attribute(string $code): ?Attribute
  {
    $this->attributesByCode ??= Attribute::query()
      ->whereIn('code', array_values(ProductContentSchema::ATTRIBUTE_COLUMNS))
      ->get()
      ->keyBy('code');

    return $this->attributesByCode->get($code);
  }

  /** Запись значения в колонку по типу атрибута (по образцу ProductUpdate::updateAttributes). */
  private function writeAttribute(Product $product, Attribute $attribute, ?ProductAttribute $existing, string $value): void
  {
    $pa = $existing ?? new ProductAttribute([
      'product_id' => $product->id,
      'attribute_id' => $attribute->id,
    ]);

    $column = $attribute->type_id === Type::ATTRIBUTE_TEXT ? 'text_value' : 'string_value';

    foreach (['string_value', 'text_value', 'int_value', 'float_value', 'attribute_value_id'] as $c) {
      $pa->{$c} = null;
    }

    $pa->{$column} = $value;
    $pa->save();
  }

  /** Фото: только товарам без единого фото. Реализация загрузчика — ProductContentPhotoImporter. */
  private function importPhotos(array $row, Product $product, bool $dryRun, ImportRowResult $result): void
  {
    $column = ProductContentSchema::COL_PHOTO_URLS;

    if (!array_key_exists($column, $row)) {
      return;
    }

    $urls = ProductContentSchema::splitMultiline($row[$column]);

    if ($urls === []) {
      return;
    }

    foreach ($urls as $url) {
      if (!preg_match('#^https?://#iu', $url)) {
        $result->errors[$column] = "не http(s)-ссылка: {$url}";
        return;
      }
    }

    if ($product->joinImg()->exists() || $product->joinImages()->exists()) {
      $result->skipped[] = $column;
      return;
    }

    if ($dryRun) {
      $result->updated[] = $column;
      return;
    }

    $outcome = $this->photos->import($product, $urls);

    if ($outcome['saved'] !== []) {
      $result->updated[] = $column;
    }

    foreach ($outcome['errors'] as $url => $reason) {
      $result->errors[$column . ': ' . $url] = $reason;
    }
  }
}
