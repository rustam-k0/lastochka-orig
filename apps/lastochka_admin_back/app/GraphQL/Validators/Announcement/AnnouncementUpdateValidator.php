<?php

namespace App\GraphQL\Validators\Announcement;

use Nuwave\Lighthouse\Validation\Validator;

final class AnnouncementUpdateValidator extends Validator
{
    /**
     * Return the validation rules.
     */
    public function rules(): array
    {
        return [
            'id' => [
                'required',
                'integer',
                'exists:announcements,id',
            ],
            'title' => [
                'nullable',
                'string',
                'max:255',
            ],
            'short_description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'description' => [
                'nullable',
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
            'id.required' => 'ID уведомления обязателен',
            'id.exists' => 'Уведомление не найдено',
            'title.max' => 'Заголовок не должен превышать 255 символов',
            'short_description.max' => 'Краткое описание не должно превышать 1000 символов',
            'promocode_id.exists' => 'Выбранный промокод не существует',
            'image.path.required_with' => 'Путь к изображению обязателен при загрузке изображения',
        ];
    }
}