<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VacancyEmploymentType;
use App\Enums\VacancyWorkFormat;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacancy>
 */
final class VacancyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => null,
            'external_id' => null,
            'title' => 'Повар горячего цеха',
            'city' => 'Москва',
            'work_format' => VacancyWorkFormat::Office,
            'employment_type' => VacancyEmploymentType::Full,
            'work_schedule' => '3/3',
            'salary' => '80 000 ₽',
            'short_description' => 'Готовим полуфабрикаты и блюда собственного производства',
            'description' => 'Ищем повара для приготовления полуфабрикатов и готовых блюд с соблюдением санитарных норм.',
            'responsibilities' => 'Приготовление блюд по технологическим картам',
            'requirements' => 'Опыт работы от полугода',
            'conditions' => 'Оформление медкнижки за счёт компании',
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/123456',
            'position' => 1,
            'is_published' => true,
            'published_at' => now(),
        ];
    }

    /**
     * Вакансия, снятая с публикации.
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
