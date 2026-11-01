<?php

namespace App\GraphQL\Validators\Page;

use App\Rules\EditorJsDocument;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class PageCreateValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            // Код необязателен: если не пришёл, генерируется из названия.
            // Уникальность проверяется по всем записям, включая мягко удалённые:
            // unique-индекс в БД их тоже видит.
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', new EditorJsDocument()],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
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
            'slug.regex' => 'Код страницы может содержать только латиницу в нижнем регистре, цифры и дефис',
            'slug.unique' => 'Страница с таким кодом уже существует',
            'slug.max' => 'Код страницы не должен превышать 100 символов',
            'title.required' => 'Название страницы обязательно',
            'title.max' => 'Название страницы не должно превышать 255 символов',
            'content.required' => 'Содержимое страницы обязательно: добавьте хотя бы один блок текста',
            'meta_title.max' => 'SEO-заголовок не должен превышать 255 символов',
            'meta_description.max' => 'SEO-описание не должно превышать 500 символов',
        ];
    }
}
