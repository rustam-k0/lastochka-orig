<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Недельный график из события 1С (MID-306). Порт App\Services\OneC\OneCWeekSchedule из admin_back:
 * витрина разбирает график 1С тем же правилом, что и админка.
 *
 * Вход: объект «monday…sunday → {start, end} или null». Выход: только присланные дни,
 * ключ — номер дня 1..7 (как StoreEventTransformer::DAY_MAP), null — выходной. Любой невалидный
 * интервал делает невалидным весь график (false): частично применённый график хуже, чем неизменный.
 */
final class OneCWeekSchedule
{
    public const DAYS = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    /**
     * @return array<int, array{start: string, end: string}|null>|false
     */
    public static function parse(mixed $raw): array|false
    {
        if ($raw === null) {
            return [];
        }

        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        if (! is_array($raw)) {
            return false;
        }

        $days = [];

        foreach (self::DAYS as $key => $day) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $interval = $raw[$key];

            if ($interval === null) {
                $days[$day] = null;

                continue;
            }

            if (is_object($interval)) {
                $interval = (array) $interval;
            }

            if (! is_array($interval)) {
                return false;
            }

            $start = self::time($interval['start'] ?? null);
            $end = self::time($interval['end'] ?? null);

            if ($start === null || $end === null || $start >= $end) {
                return false;
            }

            $days[$day] = ['start' => $start, 'end' => $end];
        }

        return $days;
    }

    private static function time(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', trim($raw), $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];

        return $hour > 23 ? null : sprintf('%02d:%s', $hour, $matches[2]);
    }
}
