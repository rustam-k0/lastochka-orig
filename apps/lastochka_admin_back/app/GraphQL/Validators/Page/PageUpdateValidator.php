<?php

namespace App\GraphQL\Validators\Page;

use App\Models\Page;
use App\Rules\EditorJsDocument;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class PageUpdateValidator extends Validator
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $id = $this->arg('id');

        return [
            'id' => ['required', 'integer', Rule::exists('pages', 'id')->whereNull('deleted_at')],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('pages', 'slug')->ignore($id),
                function ($attribute, $value, $fail) use ($id) {
                    $page = Page::find($id);

                    // На код системной страницы завязаны ссылки в мобильном приложении.
                    if ($page !== null && $page->is_system && $page->slug !== $value) {
                        $fail('Код системной страницы изменять нельзя');
                    }
                },
            ],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'required', 'string', new EditorJsDocument()],
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
            'id.exists' => 'Страница не найдена',
            'slug.required' => 'Код страницы не может быть пустым при редактировании: оставьте поле нетронутым, чтобы код не менялся',
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
