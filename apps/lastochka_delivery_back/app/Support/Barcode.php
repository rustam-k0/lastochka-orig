<?php

namespace App\Support;

/**
 * Нормализация отсканированного штрихкода для поиска по сохранённому.
 *
 * 1С отдаёт короткие штрихкоды (EAN-8 «80768258», UPC-A «873999000036»)
 * дополненными нулями до 13 знаков («0000080768258», «0873999000036»),
 * а сканер/мобилка возвращают их как есть. Бывает и наоборот — сканер
 * или касса дополняют до 13, а вручную заведённый товар хранит сырой EAN-8.
 * Поэтому ищем по набору кандидатов, толерантному к ведущим нулям в обе
 * стороны; список подходит для whereIn (индексируемо, работает в SQLite).
 */
class Barcode
{
    private const EAN13_LENGTH = 13;

    /**
     * Варианты хранимого штрихкода для отсканированного значения:
     * как есть, без ведущих нулей, дополнено нулями до 13 знаков.
     * Порядок стабилен, дубли убраны. Не-цифровой ввод — как есть.
     *
     * @return string[]
     */
    public static function storedCandidates(?string $scanned): array
    {
        if ($scanned === null || $scanned === '') {
            return [];
        }

        if (! ctype_digit($scanned)) {
            return [$scanned];
        }

        $candidates = [$scanned];

        $stripped = ltrim($scanned, '0');
        $candidates[] = $stripped === '' ? '0' : $stripped;

        if (strlen($scanned) < self::EAN13_LENGTH) {
            $candidates[] = str_pad($scanned, self::EAN13_LENGTH, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($candidates));
    }
}
