<?php

namespace App\GraphQL\Validators\Announcement;

use Nuwave\Lighthouse\Validation\Validator;

final class AnnouncementCreateValidator extends Validator
{
    /**
     * Return the validation rules.
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'short_description' => [
                'required',
                'string',
                'max:1000',
            ],
            'description' => [
                'required',
                'string',
            ],
            'promocode_id' => [
                'nullable',
                'integer',
                'exists:merchant_promocodes,id',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
            'image' => [
                'nullable',
                'array',
            ],
            'image.path' => [
                'required_with:image',
                'string',
            ],
            'image.alt' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Return the validation messages.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Заголовок обязателен для заполнения',
            'title.max' => 'Заголовок не должен превышать 255 символов',
            'short_description.required' => 'Краткое описание обязательно для заполнения',
            'short_description.max' => 'Краткое описание не должно превышать 1000 символов',
            'description.required' => 'Описание обязательно для заполнения',
            'promocode_id.exists' => 'Выбранный промокод не существует',
            'image.path.required_with' => 'Путь к изображению обязателен при загрузке изображения',
        ];
    }
}