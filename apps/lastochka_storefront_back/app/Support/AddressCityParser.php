<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Извлечение населённого пункта из адресной строки.
 *
 * 1С публикует магазины (shop.created / shop.updated) с адресом одной строкой вида
 * «г.Красноярск, ул. Калинина, 175, стр. 9» и без отдельного поля города —
 * населённый пункт вытаскиваем из адреса, чтобы проставить city_id.
 *
 * Только mb_*-функции: адреса кириллические.
 */
final class AddressCityParser
{
    /**
     * Сегмент адреса «тип населённого пункта + название».
     * Порядок альтернатив — от длинных к коротким («город» раньше «г»), после типа
     * обязательна точка или пробел, название начинается с буквы: «д. 5» (дом) и
     * «стр. 9» (строение) под правило не подходят.
     */
    private const SETTLEMENT_RE = '/^(?:город|гор|г|пгт|рп|р\.п|посёлок|поселок|пос|п|село|с|деревня|дер|д|станица|ст-ца|станция|ст|хутор|х|аул|снт|кп)(?:\.\s*|\s+)(\p{L}.*)$/iu';

    /** Сегмент — регион/район, а не населённый пункт. */
    private const REGION_RE = '/(?<!\p{L})(?:край|обл|область|республика|респ|район|р-н|округ)(?!\p{L})/iu';

    /** Сегмент начинается с типа улицы/дома — не населённый пункт. */
    private const STREET_RE = '/^(?:ул|улица|пр|пр-т|пр-кт|просп|проспект|пер|переулок|б-р|бульвар|ш|шоссе|пл|площадь|наб|набережная|тракт|проезд|туп|тупик|мкр|микрорайон|кв-л|квартал|дом|стр|строение|корп|корпус|кв|пом|оф|офис)\.?(?:\s|$)/iu';

    /**
     * Населённый пункт из адресной строки или null, если уверенно выделить нельзя.
     */
    public static function parse(?string $address): ?string
    {
        $address = self::clean($address);
        if ($address === null) {
            return null;
        }

        $segments = array_values(array_filter(
            array_map(fn (string $s) => self::clean($s), explode(',', $address)),
            fn (?string $s) => $s !== null,
        ));

        if ($segments === []) {
            return null;
        }

        foreach ($segments as $segment) {
            $name = self::settlementName($segment);
            if ($name === null) {
                continue;
            }

            // Единственный сегмент без запятых («г. Красноярск Ленина 121»): после
            // типа идёт не только название — цифры выдают улицу/дом, город не выделить.
            if (count($segments) === 1 && preg_match('/\d/u', $name)) {
                return null;
            }

            return $name;
        }

        // Без типа населённого пункта («Красноярск, ул. Ленина, 5»): берём первый
        // сегмент, если он не регион, не улица и не содержит цифр.
        if (count($segments) < 2) {
            return null;
        }

        $first = $segments[0];
        if (preg_match('/\d/u', $first) || preg_match(self::REGION_RE, $first) || preg_match(self::STREET_RE, $first)) {
            return null;
        }

        return $first;
    }

    /**
     * Название города из явного поля (city_name): убирает тип населённого пункта
     * («г. Красноярск» → «Красноярск»), лишние пробелы; пустое → null.
     */
    public static function normalizeCityName(?string $name): ?string
    {
        $name = self::clean($name);
        if ($name === null) {
            return null;
        }

        return self::settlementName($name) ?? $name;
    }

    private static function settlementName(string $segment): ?string
    {
        if (!preg_match(self::SETTLEMENT_RE, $segment, $m)) {
            return null;
        }

        return self::clean($m[1]);
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }
}
