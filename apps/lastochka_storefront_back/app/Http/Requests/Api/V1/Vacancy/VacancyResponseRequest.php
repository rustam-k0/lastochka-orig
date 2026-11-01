<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Vacancy;

use Illuminate\Foundation\Http\FormRequest;

final class VacancyResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $extensions = implode(',', (array) config('vacancies.resume.allowed_extensions', ['pdf']));
        $maxSize = (int) config('vacancies.resume.max_size_kb', 5120);

        return [
            /**
             * Имя кандидата.
             */
            'name' => ['required', 'string', 'max:255'],
            /**
             * Телефон кандидата в свободном формате.
             */
            'phone' => ['required', 'string', 'max:32'],
            /**
             * Электронная почта кандидата.
             */
            'email' => ['required', 'email', 'max:255'],
            /**
             * Сопроводительное письмо, необязательно.
             */
            'comment' => ['nullable', 'string', 'max:2000'],
            /**
             * Файл резюме, один файл. Допустимые форматы и предельный размер задаются настройкой стенда.
             */
            'resume' => ['required', 'file', 'mimes:'.$extensions, 'max:'.$maxSize],
            /**
             * Согласие на обработку персональных данных — обязательно.
             */
            'personalDataConsent' => ['required', 'accepted'],
            /**
             * Источник отправки: mobile или website.
             */
            'source' => ['nullable', 'string', 'in:mobile,website'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $extensions = strtoupper(implode(', ', (array) config('vacancies.resume.allowed_extensions', ['pdf'])));
        $maxSizeMb = round(((int) config('vacancies.resume.max_size_kb', 5120)) / 1024, 1);

        return [
            'name.required' => 'Укажите имя',
            'phone.required' => 'Укажите телефон',
            'email.required' => 'Укажите электронную почту',
            'email.email' => 'Электронная почта указана некорректно',
            'resume.required' => 'Прикрепите резюме',
            'resume.mimes' => 'Поддерживаются форматы: '.$extensions,
            'resume.max' => 'Размер резюме не должен превышать '.$maxSizeMb.' МБ',
            'personalDataConsent.accepted' => 'Необходимо согласие на обработку персональных данных',
            'source.in' => 'Недопустимый источник отправки',
        ];
    }
}
