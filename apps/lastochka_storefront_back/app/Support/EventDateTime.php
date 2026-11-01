<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Даты в payload межсервисных событий.
 *
 * Колонки дат в БД таймзону не хранят: Laravel и читает, и пишет их в
 * таймзоне приложения (config('app.timezone')). Значит дата в событии
 * обязана ехать с явным офсетом, а на приёме — приводиться к таймзоне
 * приложения. Иначе в колонку ложится стенное время чужой зоны.
 *
 * MID-272: издатели курьерки клали в payload сырой Carbon, json_encode
 * сериализовал его в UTC («2026-08-20T13:00:00.000000Z»), а консьюмер
 * присваивал строку атрибуту с кастом datetime — Laravel парсил её как UTC
 * и писал в колонку 13:00 вместо московских 16:00. Каждое событие заказа
 * уносило слот доставки на 3 часа назад (41 заказ на dev уехал от −3 до
 * −21 часа), у курьера в приложении время доставки было вчерашним.
 */
final class EventDateTime
{
    /**
     * Дата для payload события — ISO-8601 с явным офсетом.
     *
     * Сырой Carbon в payload класть нельзя: json_encode сериализует его в
     * UTC без следа исходной зоны, и приёмник не отличит «издатель прислал
     * момент в UTC» от «издатель прислал своё локальное время».
     */
    public static function toPayload(mixed $value): ?string
    {
        return self::parse($value)?->toIso8601String();
    }

    /**
     * Дата из payload события в таймзоне приложения — пригодная для записи
     * в колонку.
     *
     * Дата с офсетом («…Z», «…+07:00») переводится в таймзону приложения,
     * дата без офсета трактуется как локальная — поведение для старых
     * издателей не меняется.
     */
    public static function fromPayload(mixed $value): ?Carbon
    {
        return self::parse($value)?->setTimezone(config('app.timezone'));
    }

    private static function parse(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (!is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            // Битую дату глушим: исключение здесь увело бы в DLQ обработку
            // всего события из-за одного поля
            Log::warning('Не удалось разобрать дату из события', [
                'value' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
