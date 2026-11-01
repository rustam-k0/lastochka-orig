<?php

namespace App\GraphQL\Mutations\Vacancy;

use App\Models\Vacancy;
use Illuminate\Support\Facades\DB;

final class VacancyUpdate
{
    /**
     * Поля, которые обновляются только если пришли в запросе:
     * частичное обновление не должно затирать остальные данные вакансии.
     */
    private const UPDATABLE_FIELDS = [
        'title',
        'city',
        'work_format',
        'employment_type',
        'work_schedule',
        'salary',
        'short_description',
        'description',
        'responsibilities',
        'requirements',
        'conditions',
        'link_title',
        'link_url',
        'position',
    ];

    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Vacancy
    {
        return DB::transaction(function () use ($args) {
            $vacancy = Vacancy::findOrFail($args['id']);

            $attributes = [];

            foreach (self::UPDATABLE_FIELDS as $field) {
                if (array_key_exists($field, $args)) {
                    $attributes[$field] = $args[$field];
                }
            }

            if (array_key_exists('is_published', $args)) {
                $isPublished = (bool) $args['is_published'];
                $attributes['is_published'] = $isPublished;

                // Дата публикации обновляется только при переходе
                // «не опубликована» → «опубликована».
                if ($isPublished && !$vacancy->is_published) {
                    $attributes['published_at'] = now();
                }
            }

            $vacancy->update($attributes);

            return $vacancy->fresh();
        });
    }
}
