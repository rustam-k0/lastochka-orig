<?php

declare(strict_types=1);

namespace App\Enums;

enum LinkType: string
{
    /** Без ссылки */
    case None = 'none';

    /** Ссылка на товар */
    case Product = 'product';

    /** Ссылка на магазин */
    case Store = 'store';

    /** Ссылка на магазин (алиас от внешней системы) */
    case Shop = 'shop';

    /** Ссылка на категорию */
    case Category = 'category';

    /** Внешняя ссылка (URL) */
    case External = 'external';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'Без ссылки',
            self::Product => 'Товар',
            self::Store, self::Shop => 'Магазин',
            self::Category => 'Категория',
            self::External => 'Внешняя ссылка',
        };
    }
}
