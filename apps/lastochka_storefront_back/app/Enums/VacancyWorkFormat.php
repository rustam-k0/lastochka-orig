<?php

declare(strict_types=1);

namespace App\Enums;

enum VacancyWorkFormat: string
{
    /** Работа в офисе / на точке */
    case Office = 'office';

    /** Удалённая работа */
    case Remote = 'remote';

    /** Гибридный формат */
    case Hybrid = 'hybrid';

    /** Разъездной формат */
    case Field = 'field';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Office => 'Офис',
            self::Remote => 'Удалённая работа',
            self::Hybrid => 'Гибридный формат',
            self::Field => 'Разъездной формат',
        };
    }
}
