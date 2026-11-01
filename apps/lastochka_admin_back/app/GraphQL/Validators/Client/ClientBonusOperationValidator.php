<?php

namespace App\GraphQL\Validators\Client;

use App\Models\Client;
use Nuwave\Lighthouse\Validation\Validator;

final class ClientBonusOperationValidator extends Validator
{
    public function rules(): array
    {
        return [
            'clientId' => ['required', 'integer', 'exists:clients,id'],
            'type' => ['required', 'in:earn,spend'],
            // max:500 у Laravel считает символы через mb_strlen, кириллица не ломает лимит
            'comment' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Тип операции должен быть earn или spend',
            'comment.required' => 'Укажите комментарий к операции',
            'comment.max' => 'Комментарий не длиннее 500 символов',
            'amount.min' => 'Сумма должна быть больше нуля',
            'clientId.exists' => 'Клиент не найден',
        ];
    }
}
