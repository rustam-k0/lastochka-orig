<?php

namespace App\GraphQL\Validators\Vacancy;

use App\Models\Vacancy;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class VacancyUpdateValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', Rule::exists('vacancies', 'id')->whereNull('deleted_at')],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'work_format' => ['nullable', Rule::in(Vacancy::workFormats())],
            'employment_type' => ['nullable', Rule::in(Vacancy::employmentTypes())],
            'work_schedule' => ['nullable', 'string', 'max:255'],
            'salary' => ['nullable', 'string', 'max:255'],
            'short_description' => ['sometimes', 'required', 'string'],
            'description' => ['sometimes', 'required', 'string'],
            'responsibilities' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
            'conditions' => ['nullable', 'string'],
            'link_title' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'string', 'url', 'max:2048', 'required_with:link_title'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.exists' => 'Вакансия не найдена',
            'title.required' => 'Наименование вакансии обязательно',
            'title.max' => 'Наименование вакансии не должно превышать 255 символов',
            'short_description.required' => 'Краткое описание обязательно',
            'description.required' => 'Полное описание обязательно',
            'link_title.max' => 'Название ссылки не должно превышать 255 символов',
            'link_url.url' => 'Ссылка на вакансию должна быть корректным адресом, например https://hh.ru/vacancy/123',
            'link_url.max' => 'Ссылка на вакансию не должна превышать 2048 символов',
            'link_url.required_with' => 'Укажите ссылку на вакансию или очистите её название',
        ];
    }
}
