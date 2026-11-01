<?php

namespace App\Services\ProductContent;

/**
 * Контракт файла выгрузки/импорта контента товаров (LASTOCHKI-JAMI-12027).
 *
 * Единственный источник правды о структуре XLSX: порядок колонок листа
 * «Товары», какие колонки читает обратный импорт и на какие поля/атрибуты
 * они ложатся. Экспорт и импорт обязаны ходить только через эти константы —
 * тогда формат не разъедется.
 */
class ProductContentSchema
{
  // Ключ сопоставления (менять в файле нельзя)
  public const COL_ID = 'id';

  // Справочные колонки (импорт игнорирует)
  public const COL_TITLE = 'Название';
  public const COL_ARTICUL = 'Артикул';
  public const COL_CODE_1C = 'Код 1С';
  public const COL_BARCODES = 'Штрих-коды';
  public const COL_CATEGORY = 'Категория';
  public const COL_UNIT = 'Ед. измерения';
  public const COL_WEIGHT = 'Вес';
  public const COL_PRICE = 'Цена';
  public const COL_STOCK = 'Остаток';
  public const COL_STATUS = 'Статус';
  public const COL_DELETED = 'Удалён';
  public const COL_FILL_PERCENT = '% заполнения';
  public const COL_PHOTO_COUNT = 'Есть фото';

  // Заполняемые колонки (импорт читает)
  public const COL_DESCRIPTION = 'Описание';
  public const COL_PROTEINS = 'Белки';
  public const COL_FATS = 'Жиры';
  public const COL_CARBS = 'Углеводы';
  public const COL_CALORIES = 'Ккал';
  public const COL_COMPOSITION = 'Состав';
  public const COL_STORAGE_CONDITIONS = 'Условия хранения';
  public const COL_SHELF_LIFE = 'Срок хранения';
  public const COL_GROSS_WEIGHT = 'Вес брутто';
  public const COL_NET_WEIGHT = 'Вес нетто';
  public const COL_DIMENSIONS = 'Габариты';
  public const COL_PHOTO_URLS = 'Фото (URL)';
  public const COL_META_TITLE = 'meta_title';
  public const COL_META_DESCRIPTION = 'meta_description';
  public const COL_META_KEYWORDS = 'meta_keywords';

  /** Порядок колонок листа «Товары». */
  public const HEADERS = [
    self::COL_ID,
    self::COL_TITLE,
    self::COL_ARTICUL,
    self::COL_CODE_1C,
    self::COL_BARCODES,
    self::COL_CATEGORY,
    self::COL_UNIT,
    self::COL_WEIGHT,
    self::COL_PRICE,
    self::COL_STOCK,
    self::COL_STATUS,
    self::COL_DELETED,
    self::COL_FILL_PERCENT,
    self::COL_PHOTO_COUNT,
    self::COL_DESCRIPTION,
    self::COL_PROTEINS,
    self::COL_FATS,
    self::COL_CARBS,
    self::COL_CALORIES,
    self::COL_COMPOSITION,
    self::COL_STORAGE_CONDITIONS,
    self::COL_SHELF_LIFE,
    self::COL_GROSS_WEIGHT,
    self::COL_NET_WEIGHT,
    self::COL_DIMENSIONS,
    self::COL_PHOTO_URLS,
    self::COL_META_TITLE,
    self::COL_META_DESCRIPTION,
    self::COL_META_KEYWORDS,
  ];

  /** Заполняемые колонки, лежащие прямо в products: колонка => поле. */
  public const PRODUCT_FIELDS = [
    self::COL_DESCRIPTION => 'description',
    self::COL_META_TITLE => 'meta_title',
    self::COL_META_DESCRIPTION => 'meta_description',
    self::COL_META_KEYWORDS => 'meta_keywords',
  ];

  /** Заполняемые колонки-атрибуты: колонка => attributes.code. */
  public const ATTRIBUTE_COLUMNS = [
    self::COL_PROTEINS => 'proteins',
    self::COL_FATS => 'fats',
    self::COL_CARBS => 'carbs',
    self::COL_CALORIES => 'calories',
    self::COL_COMPOSITION => 'composition',
    self::COL_STORAGE_CONDITIONS => 'storage_conditions',
    self::COL_SHELF_LIFE => 'shelf_life',
    self::COL_GROSS_WEIGHT => 'gross_weight',
    self::COL_NET_WEIGHT => 'net_weight',
    self::COL_DIMENSIONS => 'dimensions',
  ];

  /** Атрибуты с числовой валидацией (БЖУ + калорийность). */
  public const NUMERIC_ATTRIBUTE_CODES = ['proteins', 'fats', 'carbs', 'calories'];

  /** Лимит длины строковых атрибутов и meta_title (колонки varchar(255)). */
  public const STRING_MAX_LENGTH = 255;

  /**
   * Пустое значение контента: NULL, пустая строка, пробелы, заглушка '0'
   * (ей 1С/перенос из Аладина помечают незаполненные БЖУ).
   */
  public static function isEmptyValue(?string $value): bool
  {
    if ($value === null) {
      return true;
    }

    $trimmed = trim($value);

    return $trimmed === '' || $trimmed === '0';
  }

  /** Многострочная ячейка (URL фото, штрих-коды) => массив непустых строк. */
  public static function splitMultiline(?string $cell): array
  {
    if ($cell === null) {
      return [];
    }

    $lines = preg_split('/\r\n|\r|\n/u', $cell) ?: [];

    return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
  }

  /** Строки листа «Инструкция»: [колонка, правила заполнения]. */
  public static function instructionRows(): array
  {
    return [
      ['Колонка', 'Правила заполнения'],
      [self::COL_ID, 'НЕ МЕНЯТЬ. Ключ товара, по нему выполняется обратный импорт.'],
      [self::COL_TITLE, 'Справочно, не менять — импорт игнорирует.'],
      [self::COL_ARTICUL, 'Справочно, не менять — импорт игнорирует.'],
      [self::COL_CODE_1C, 'Справочно, не менять — импорт игнорирует.'],
      [self::COL_BARCODES, 'Справочно (все штрих-коды товара, каждый с новой строки). Не менять.'],
      [self::COL_CATEGORY, 'Справочно, цепочка категорий через « > ». Не менять.'],
      [self::COL_UNIT, 'Справочно: шт/г/кг/л/мл. Не менять.'],
      [self::COL_WEIGHT, 'Справочно, вес/объём товара в единице из колонки «Ед. измерения». Не менять.'],
      [self::COL_PRICE, 'Справочно. Не менять — импорт цены не обновляет.'],
      [self::COL_STOCK, 'Справочно. Не менять — импорт остатки не обновляет.'],
      [self::COL_STATUS, 'Справочно: активен/скрыт. Не менять — импорт статус не трогает.'],
      [self::COL_DELETED, 'Справочно. Не менять.'],
      [self::COL_FILL_PERCENT, 'Справочно, % заполненности карточки на момент выгрузки. Не менять.'],
      [self::COL_PHOTO_COUNT, 'Справочно, сколько фото уже есть у товара. Не менять.'],
      [self::COL_DESCRIPTION, 'Текст описания товара. Без HTML. Заполнять, если пусто.'],
      [self::COL_PROTEINS, 'Число на 100 г, точка или запятая (пример: 8,6). Допустим суффикс единицы: «8,6 г».'],
      [self::COL_FATS, 'Число на 100 г, точка или запятая. Допустим суффикс единицы.'],
      [self::COL_CARBS, 'Число на 100 г, точка или запятая. Допустим суффикс единицы.'],
      [self::COL_CALORIES, 'Число на 100 г (ккал), точка или запятая. Допустим суффикс: «250 ккал».'],
      [self::COL_COMPOSITION, 'Состав продукта текстом, ингредиенты через запятую.'],
      [self::COL_STORAGE_CONDITIONS, 'Например: «при температуре от +2 до +6 °C». До 255 символов.'],
      [self::COL_SHELF_LIFE, 'Например: «6 месяцев», «72 часа». До 255 символов.'],
      [self::COL_GROSS_WEIGHT, 'Вес брутто, например «250 г». До 255 символов.'],
      [self::COL_NET_WEIGHT, 'Вес нетто, например «230 г». До 255 символов.'],
      [self::COL_DIMENSIONS, 'Габариты упаковки, например «10x5x3 см». До 255 символов.'],
      [self::COL_PHOTO_URLS, 'Прямые http(s)-ссылки на изображения (jpg/png/webp, до 10 МБ). Несколько — каждая с новой строки (Alt+Enter в Excel). ПЕРВАЯ ссылка станет главным фото. Фото добавятся только товарам, у которых «Есть фото» = 0.'],
      [self::COL_META_TITLE, 'SEO-заголовок. До 255 символов. Необязательно.'],
      [self::COL_META_DESCRIPTION, 'SEO-описание. Необязательно.'],
      [self::COL_META_KEYWORDS, 'SEO-ключевые слова через запятую. Необязательно.'],
      ['', ''],
      ['ОБЩИЕ ПРАВИЛА', 'Заполняются только пустые ячейки заполняемых колонок. Значения, уже заполненные в системе, при импорте НЕ перезаписываются. Пустая ячейка ничего не стирает. Строки удалять нельзя, порядок и названия колонок менять нельзя. Кодировка UTF-8. Если данных нет — оставляйте ячейку пустой, НЕ пишите 0: одиночный 0 считается заглушкой и игнорируется импортом. Реальное нулевое значение БЖУ указывайте с единицей: «0 г».'],
    ];
  }
}
