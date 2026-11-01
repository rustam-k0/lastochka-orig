<?php

namespace App\Support;

/**
 * Весовой/внутримагазинный штрихкод с встроенным кодом товара.
 *
 * Формат: префикс 20–29 + 5 цифр кода номенклатуры 1С + остаток (вес/цена + к.ц.).
 * Префиксы 20–29 в EAN-13 целиком зарезервированы под внутреннее обращение
 * магазина и никогда не используются производителями — поэтому штрихкод с таким
 * префиксом это всегда внутренний весовой код, а не реальный EAN товара.
 * Конкретный префикс (20/21/…/29) зависит от настройки кассы/1С и значения не имеет.
 *
 * При сканировании из такого штрихкода извлекается код товара (`products.code`),
 * по которому ищется номенклатура.
 */
class WeightedBarcode
{
    private const PREFIX_MIN = 20;
    private const PREFIX_MAX = 29;
    private const PREFIX_LENGTH = 2;
    private const CODE_LENGTH = 5;
    private const EAN13_LENGTH = 13;

    /**
     * Содержит ли штрихкод встроенный код товара: полный EAN-13 (13 цифр)
     * с префиксом 20–29. Реальные весовые штрихкоды всегда 13-значные
     * (префикс 2 + код 5 + вес/цена 5 + к.ц. 1), поэтому требуем точную длину —
     * это отсекает обычные короткие штрихкоды/коды, случайно начинающиеся с 20–29.
     */
    public static function hasEmbeddedCode(?string $barcode): bool
    {
        if ($barcode === null) {
            return false;
        }

        if (strlen($barcode) !== self::EAN13_LENGTH || ! ctype_digit($barcode)) {
            return false;
        }

        $prefixValue = (int) substr($barcode, 0, self::PREFIX_LENGTH);

        return $prefixValue >= self::PREFIX_MIN && $prefixValue <= self::PREFIX_MAX;
    }

    /**
     * Извлечь код товара (5 цифр после префикса) без ведущих нулей или null,
     * если штрихкод не весовой.
     *
     * Ведущие нули в коде из штрихкода — это padding до 5 знаков («пустота»),
     * а в `products.code` код 1С хранится без них (напр. «296», не «00296»).
     * Поэтому нормализуем: «00296» → «296». Код из одних нулей → «0».
     */
    public static function extractCode(?string $barcode): ?string
    {
        if (! self::hasEmbeddedCode($barcode)) {
            return null;
        }

        $code = ltrim(substr($barcode, self::PREFIX_LENGTH, self::CODE_LENGTH), '0');

        return $code === '' ? '0' : $code;
    }

    /**
     * Кандидаты значения `products.code` для поиска, толерантные к ведущим нулям
     * padding-а: код «296» из штрихкода матчит хранимые «296», «0296», «00296».
     * Возвращает все варианты с 0..N ведущими нулями до длины 5 (для `whereIn`,
     * индексируемо и портируемо между MySQL/SQLite). Пустой массив — не весовой.
     *
     * @return string[]
     */
    public static function codeCandidates(?string $barcode): array
    {
        $code = self::extractCode($barcode);

        if ($code === null) {
            return [];
        }

        $candidates = [];
        for ($length = strlen($code); $length <= self::CODE_LENGTH; $length++) {
            $candidates[] = str_pad($code, $length, '0', STR_PAD_LEFT);
        }

        return $candidates;
    }
}
