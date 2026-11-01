<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Способ наполнения товарной группы (product_groups.fill_type).
 *
 * Источник — admin_back (MerchantProductGroup.fill_type). Сочетать критерий
 * с ручным выбором товаров или с другим критерием нельзя.
 */
enum ProductGroupFillType: string
{
    /** Товары выбраны админом (product_group_items). */
    case Manual = 'manual';

    /** Личная: товары, которые пользователь получил в доставленных заказах. */
    case PurchaseHistory = 'purchase_history';

    /** Личная: товары, карточку которых пользователь открывал и не купил. */
    case ViewedNotBought = 'viewed_not_bought';

    /** Общая: товары с галочкой «Новинка». */
    case IsNew = 'is_new';

    /** Общая: товары с галочкой «Успей купить». */
    case IsHurryUp = 'is_hurry_up';

    /**
     * Значение из БД/события; неизвестное и пустое → manual.
     */
    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Manual) : self::Manual;
    }

    /** Группа собирается под конкретного пользователя (гостю не отдаётся). */
    public function isPersonal(): bool
    {
        return $this === self::PurchaseHistory || $this === self::ViewedNotBought;
    }

    /** Товары подбираются критерием, а не выбираются админом. */
    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }
}
