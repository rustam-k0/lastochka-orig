<?php

namespace App\GraphQL\Mutations\Vacancy;

use App\Models\Vacancy;
use App\Services\VacancyService;

final class VacancyChangePosition
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Vacancy
    {
        $vacancy = Vacancy::findOrFail($args['id']);

        return (new VacancyService())->changePosition($vacancy, (int) $args['position']);
    }
}
