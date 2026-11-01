<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Address;

use Illuminate\Foundation\Http\FormRequest;

final class UploadPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Массив multipart-файлов фото объекта (1–6 за один запрос). Дополнительная серверная проверка: суммарно ≤6 фото на один адрес.
             */
            'photos' => ['required', 'array', 'min:1', 'max:6'],
            /**
             * Каждый файл — изображение jpg/jpeg/png, до 5 МБ.
             */
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required' => 'Загрузите хотя бы одно фото',
            'photos.max' => 'Максимум 6 фото за один запрос',
            'photos.*.image' => 'Файл должен быть изображением',
            'photos.*.mimes' => 'Поддерживаются только jpg и png',
            'photos.*.max' => 'Размер фото не должен превышать 5 МБ',
        ];
    }
}
