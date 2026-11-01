<?php

namespace App\GraphQL\Mutations\Vacancy;

use App\Models\Vacancy;
use App\Services\VacancyService;
use Illuminate\Support\Facades\DB;

final class VacancyCreate
{
    /**
     * @param null $_
     * @param array{} $args
     */
    public function __invoke($_, array $args): Vacancy
    {
        return DB::transaction(function () use ($args) {
            $service = new VacancyService();
            $isPublished = (bool) ($args['is_published'] ?? false);

            return Vacancy::create([
                'title' => $args['title'],
                'city' => $args['city'] ?? null,
                'work_format' => $args['work_format'] ?? null,
                'employment_type' => $args['employment_type'] ?? null,
                'work_schedule' => $args['work_schedule'] ?? null,
                'salary' => $args['salary'] ?? null,
                'short_description' => $args['short_description'],
                'description' => $args['description'],
                'responsibilities' => $args['responsibilities'] ?? null,
                'requirements' => $args['requirements'] ?? null,
                'conditions' => $args['conditions'] ?? null,
                'link_title' => $args['link_title'] ?? null,
                'link_url' => $args['link_url'] ?? null,
                'position' => $args['position'] ?? $service->nextPosition(),
                'is_published' => $isPublished,
                // Дата публикации ведётся системой: проставляется в момент,
                // когда вакансия становится опубликованной.
                'published_at' => $isPublished ? now() : null,
            ]);
        });
    }
}
