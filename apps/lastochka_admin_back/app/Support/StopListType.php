<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Стоп-листы магазина — представление полей карточки товара (Platrum 12188):
 *
 * «hidden»       — товары со статусом «не активный» (status_id != CODE_ACTIVE).
 * «pickup_only»  — товары с включённым «только самовывоз» (pickup_only = true).
 *
 * Дата попадания в стоп-лист = hidden_at / pickup_only_at (ведут мутаторы
 * модели Product при смене статуса / самовывоза).
 */
final class StopListType
{
    public const HIDDEN = 'hidden';
    public const PICKUP_ONLY = 'pickup_only';

    /** type => колонка даты попадания в стоп-лист (для выдачи и сортировки) */
    private const DATE_COLUMNS = [
        self::HIDDEN => 'hidden_at',
        self::PICKUP_ONLY => 'pickup_only_at',
    ];

    /** @return string[] */
    public static function values(): array
    {
        return array_keys(self::DATE_COLUMNS);
    }

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::DATE_COLUMNS);
    }

    public static function dateColumn(string $type): string
    {
        return self::DATE_COLUMNS[$type];
    }

    /**
     * Валидировать тип; при недопустимом — бросить Lighthouse ValidationException
     * (category=validation, поле type). Фронт понимает только category=validation.
     */
    public static function assertValid(string $type): void
    {
        if (!self::isValid($type)) {
            throw ValidationException::withMessages([
                'type' => ['Недопустимый тип стоп-листа. Допустимо: hidden, pickup_only.'],
            ]);
        }
    }
}
