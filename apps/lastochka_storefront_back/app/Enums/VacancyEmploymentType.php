<?php

declare(strict_types=1);

namespace App\Enums;

enum VacancyEmploymentType: string
{
    /** Полная занятость */
    case Full = 'full';

    /** Частичная занятость */
    case Part = 'part';

    /** Временная занятость */
    case Temporary = 'temporary';

    /** Иная занятость */
    case Other = 'other';

    /**
     * Локализованное название.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Полная занятость',
            self::Part => 'Частичная занятость',
            self::Temporary => 'Временная занятость',
            self::Other => 'Иная занятость',
        };
    }
}
