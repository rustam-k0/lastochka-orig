<?php

namespace App\GraphQL\Mutations\Vacancy;

use App\Models\Vacancy;

final class VacancyDelete
{
    /**
     * Удаление вакансии. Опубликованную вакансию удалять разрешено —
     * снимать с публикации предварительно не требуется.
     *
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args)
    {
        $vacancy = Vacancy::findOrFail($args['id']);

        $vacancy->delete();

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
