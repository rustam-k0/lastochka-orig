<?php

declare(strict_types=1);

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Буквенные обозначения колонок листа по их количеству.
 */
class ExportColumns
{
    /**
     * @return array<int, string> Например 3 → ['A', 'B', 'C']
     */
    public static function letters(int $count): array
    {
        $letters = [];

        for ($i = 1; $i <= $count; $i++) {
            $letters[] = Coordinate::stringFromColumnIndex($i);
        }

        return $letters;
    }
}
