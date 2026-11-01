<?php

namespace App\Enums;

/**
 * Тип ошибки сборки по отзыву на заказ. Проставляет оператор в админке,
 * ошибка относится на сборщика заказа (assemblies.user_id) в отчёте
 * «Качество сборки» (Superset, «Операционные отчёты»).
 */
enum OrderReviewErrorType: string
{
    /** Недовоз: в заказе не хватило товара */
    case SHORTAGE = 'shortage';

    /** Пересорт: привезли не тот товар */
    case WRONG_ITEM = 'wrong_item';

    /** Просрочка: истёк срок годности */
    case EXPIRED = 'expired';

    /** Другая ошибка сборки */
    case OTHER = 'other';

    /**
     * Все допустимые значения (для валидации `in:`).
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Название типа ошибки в админке и отчётах.
     */
    public function label(): string
    {
        return match ($this) {
            self::SHORTAGE => 'Недовоз',
            self::WRONG_ITEM => 'Пересорт',
            self::EXPIRED => 'Просрочка',
            self::OTHER => 'Другое',
        };
    }
}
