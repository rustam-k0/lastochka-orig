<?php

namespace App\GraphQL\Queries\Vacancy;

use App\Models\Vacancy;

final class VacancyCities
{
    /**
     * Города, по которым заведены вакансии — источник значений
     * для фильтра «Город» в списке вакансий.
     *
     * @param null $_
     * @param array{} $args
     * @return array<string>
     */
    public function __invoke($_, array $args): array
    {
        return Vacancy::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city')
            ->all();
    }
}
