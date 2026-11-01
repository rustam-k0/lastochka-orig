<?php

namespace App\GraphQL\Mutations\Vacancy;

use App\Models\Vacancy;

final class VacancyUnpublish
{
    /**
     * Снятие вакансии с публикации: вакансия перестаёт отображаться
     * пользователям, отправка новых откликов по ней недоступна.
     * Дата последней публикации сохраняется.
     *
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Vacancy
    {
        $vacancy = Vacancy::findOrFail($args['id']);

        $vacancy->update(['is_published' => false]);

        return $vacancy->fresh();
    }
}
