<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Feedback;

use Illuminate\Foundation\Http\FormRequest;

final class SendFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Имя отправителя.
             *
             * @example Иван Иванов
             */
            'name' => ['required', 'string', 'max:255'],
            /**
             * Контактный телефон (опционально).
             *
             * @example +7 999 123-45-67
             */
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            /**
             * Электронная почта для ответа (опционально).
             *
             * @example ivan@example.com
             */
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            /**
             * Текст обращения.
             *
             * @example Не пришёл чек по заказу №123
             */
            'message' => ['required', 'string', 'max:5000'],
            /**
             * Согласие на обработку персональных данных.
             *
             * @example true
             */
            'personalDataConsent' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя',
            'email.email' => 'Неверный формат электронной почты',
            'message.required' => 'Напишите сообщение',
            'message.max' => 'Сообщение не должно превышать 5000 символов',
            'personalDataConsent.required' => 'Необходимо согласие на обработку персональных данных',
            'personalDataConsent.accepted' => 'Необходимо согласие на обработку персональных данных',
        ];
    }
}
