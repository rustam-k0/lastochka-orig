<?php

namespace App\Services\ProductContent;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Status;
use App\Models\Type;
use App\Support\StockUnits;
use Illuminate\Support\Collection;

/**
 * Выгрузка номенклатуры товаров для заполнения парсером (LASTOCHKI-JAMI-12027).
 *
 * Строки листа «Товары»: порядок колонок — ProductContentSchema::HEADERS.
 * Все значения строками — чтобы Excel не превращал штрихкоды в экспоненту.
 */
class ProductContentExportService
{
  /** info_weight_type_id => слово в выгрузке. */
  public const UNIT_LABELS = [
    Type::INFO_WEIGHT_PIECE => 'шт',
    Type::INFO_WEIGHT_GRAM => 'г',
    Type::INFO_WEIGHT_KILOGRAM => 'кг',
    Type::INFO_WEIGHT_LITER => 'л',
    Type::INFO_WEIGHT_M_LITER => 'мл',
  ];

  /** Карта категорий id => [title, parent_id] — для цепочек без N+1. */
  private ?Collection $categoryMap = null;

  /**
   * Генератор строк листа «Товары»: 13k строк не держим в памяти разом.
   *
   * @param bool $onlyUnfilled только товары, где не хватает контента
   *   (описание, любой из 10 атрибутов, фото; meta_* в критерий не входят)
   * @return \Generator<int, array<int, string>>
   */
  public function rows(bool $onlyUnfilled = false): \Generator
  {
    $query = Product::withTrashed()
      ->with([
        'attributes.attribute',
        'joinImg',
        'joinImages',
        'categories',
        'category1c',
        'defaultPrice',
        'remains',
      ])
      ->orderBy('id');

    foreach ($query->lazyById(500) as $product) {
      if ($onlyUnfilled && $this->isFullyFilled($product)) {
        continue;
      }

      yield $this->productRow($product);
    }
  }

  /**
   * Остаток с единицей: он в базовой единице 1С (12.806 кг), а «Ед. измерения»
   * и «Вес» описывают порцию (г / 300) — голое «12» читалось как 12 граммов.
   */
  private function stock(Product $product): string
  {
    $quantity = round((float) $product->remains->sum('quantity'), 3);
    $unit = self::UNIT_LABELS[StockUnits::stockUnit($product)] ?? 'шт';

    return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') . ' ' . $unit;
  }

  /** @return array<int, string> значения в порядке ProductContentSchema::HEADERS */
  public function productRow(Product $product): array
  {
    $attrs = $this->attributeValues($product);

    return [
      (string) $product->id,
      (string) $product->title,
      (string) ($product->articul ?? ''),
      (string) ($product->code ?? ''),
      $this->barcodes($product),
      $this->categoryChain($product),
      self::UNIT_LABELS[$product->info_weight_type_id] ?? '',
      $product->info_weight !== null ? (string) $product->info_weight : '',
      $product->defaultPrice !== null ? (string) $product->defaultPrice->value : '',
      $this->stock($product),
      $product->status_id === Status::CODE_ACTIVE ? 'активен' : 'скрыт',
      $product->trashed() ? 'да' : '',
      (string) $product->fill_percent,
      (string) $this->photoCount($product),
      $this->cleanValue($product->description),
      $attrs['proteins'] ?? '',
      $attrs['fats'] ?? '',
      $attrs['carbs'] ?? '',
      $attrs['calories'] ?? '',
      $attrs['composition'] ?? '',
      $attrs['storage_conditions'] ?? '',
      $attrs['shelf_life'] ?? '',
      $attrs['gross_weight'] ?? '',
      $attrs['net_weight'] ?? '',
      $attrs['dimensions'] ?? '',
      '', // Фото (URL) — заполняет парсер
      $this->cleanValue($product->meta_title),
      $this->cleanValue($product->meta_description),
      $this->cleanValue($product->meta_keywords),
    ];
  }

  /** Пусто/заглушка '0' => пустая ячейка, иначе — значение как есть. */
  private function cleanValue(?string $value): string
  {
    return ProductContentSchema::isEmptyValue($value) ? '' : trim($value);
  }

  /** @return array<string, string> attributes.code => значение ('' если заглушка) */
  private function attributeValues(Product $product): array
  {
    $out = [];

    foreach ($product->attributes as $pa) {
      $code = $pa->attribute?->code;
      if (!$code) {
        continue;
      }

      $out[$code] = $this->cleanValue($this->rawAttributeValue($pa));
    }

    return $out;
  }

  private function rawAttributeValue(ProductAttribute $pa): ?string
  {
    foreach (['string_value', 'text_value', 'int_value', 'float_value'] as $column) {
      if ($pa->{$column} !== null && $pa->{$column} !== '') {
        return (string) $pa->{$column};
      }
    }

    return null;
  }

  /** Все штрих-коды товара (barcode + barcodes JSON), уникальные, через \n. */
  private function barcodes(Product $product): string
  {
    $all = array_filter(array_merge([$product->barcode], $product->barcodes ?? []));

    return implode("\n", array_values(array_unique($all)));
  }

  /** Цепочка «Родитель > ... > Лист» первой категории; fallback — категория 1С. */
  private function categoryChain(Product $product): string
  {
    $leaf = $product->categories->first();

    if (!$leaf) {
      return (string) ($product->category1c?->title ?? '');
    }

    $this->categoryMap ??= Category::withTrashed()
      ->get(['id', 'title', 'parent_id'])
      ->keyBy('id');

    $titles = [];
    $currentId = $leaf->id;

    // Ограничитель глубины — защита от цикла в parent_id
    for ($depth = 0; $depth < 10 && $currentId !== null; $depth++) {
      $node = $this->categoryMap->get($currentId);
      if (!$node) {
        break;
      }
      $titles[] = $node->title;
      $currentId = $node->parent_id;
    }

    return implode(' > ', array_reverse($titles));
  }

  private function photoCount(Product $product): int
  {
    return ($product->joinImg ? 1 : 0) + $product->joinImages->count();
  }

  /** Товар полностью заполнен: описание + все 10 атрибутов + есть фото. */
  private function isFullyFilled(Product $product): bool
  {
    if (ProductContentSchema::isEmptyValue($product->description)) {
      return false;
    }

    if ($this->photoCount($product) === 0) {
      return false;
    }

    $attrs = $this->attributeValues($product);

    foreach (ProductContentSchema::ATTRIBUTE_COLUMNS as $code) {
      if (($attrs[$code] ?? '') === '') {
        return false;
      }
    }

    return true;
  }
}
