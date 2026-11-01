<?php

namespace App\GraphQL\Mutations\Vacancy;

use App\Models\Vacancy;

final class VacancyPublish
{
    /**
     * Публикация вакансии: вакансия становится видна пользователям
     * в мобильном приложении и на сайте, отклик по ней доступен.
     *
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Vacancy
    {
        $vacancy = Vacancy::findOrFail($args['id']);

        $vacancy->update([
            'is_published' => true,
            'published_at' => now(),
        ]);

        return $vacancy->fresh();
    }
}
