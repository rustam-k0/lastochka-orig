<?php

namespace Database\Factories;

use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacancy>
 */
class VacancyFactory extends Factory
{
    protected $model = Vacancy::class;

    public function definition(): array
    {
        return [
            'title' => 'Повар горячего цеха',
            'city' => 'Москва',
            'work_format' => Vacancy::WORK_FORMAT_OFFICE,
            'employment_type' => Vacancy::EMPLOYMENT_TYPE_FULL,
            'work_schedule' => '3/3',
            'salary' => '80 000 ₽',
            'short_description' => 'Готовим полуфабрикаты и блюда собственного производства',
            'description' => 'Ищем повара для приготовления полуфабрикатов и готовых блюд с соблюдением санитарных норм.',
            'responsibilities' => 'Приготовление блюд по технологическим картам',
            'requirements' => 'Опыт работы от полугода',
            'conditions' => 'Оформление медкнижки за счёт компании',
            'link_title' => 'Смотреть на hh.ru',
            'link_url' => 'https://hh.ru/vacancy/123456',
            'position' => null,
            'is_published' => false,
            'published_at' => null,
        ];
    }

    /**
     * Опубликованная вакансия.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
