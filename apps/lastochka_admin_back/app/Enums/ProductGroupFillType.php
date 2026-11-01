<?php

namespace App\Enums;

/**
 * Способ наполнения товарной группы.
 *
 * manual — товары выбирает админ (pivot merchant_product_group_items).
 * Остальные — автоматические критерии: товары группы собирает витрина
 * (storefront_back), в admin_back у такой группы список товаров пуст.
 */
enum ProductGroupFillType: string
{
    /** Вручную: товары, выбранные админом */
    case MANUAL = 'manual';

    /** Из истории заказов: товары из доставленных заказов пользователя (личная) */
    case PURCHASE_HISTORY = 'purchase_history';

    /** Смотрел, но не купил: просмотренные и не купленные товары (личная) */
    case VIEWED_NOT_BOUGHT = 'viewed_not_bought';

    /** Новинки: товары с галочкой «Новинка» */
    case IS_NEW = 'is_new';

    /** Успей купить: товары с галочкой «Успей купить» */
    case IS_HURRY_UP = 'is_hurry_up';

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
     * Название способа наполнения в админке.
     */
    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Вручную',
            self::PURCHASE_HISTORY => 'Из истории заказов',
            self::VIEWED_NOT_BOUGHT => 'Смотрел, но не купил',
            self::IS_NEW => 'Новинки',
            self::IS_HURRY_UP => 'Успей купить',
        };
    }

    /**
     * Ручное наполнение: товары группы хранятся в pivot и редактируются админом.
     */
    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }

    /**
     * Личная группа: состав зависит от пользователя, гостю не отдаётся.
     */
    public function isPersonal(): bool
    {
        return in_array($this, [self::PURCHASE_HISTORY, self::VIEWED_NOT_BOUGHT], true);
    }

    /**
     * Ручное ли наполнение для сырого значения (null и неизвестное = manual).
     */
    public static function isManualValue(?string $value): bool
    {
        return (self::tryFrom((string) $value) ?? self::MANUAL)->isManual();
    }
}
