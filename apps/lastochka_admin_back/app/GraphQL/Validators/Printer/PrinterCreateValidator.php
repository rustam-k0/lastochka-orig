<?php

namespace App\GraphQL\Validators\Printer;

use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class PrinterCreateValidator extends Validator
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'ip_address' => [
                'required',
                'string',
                'ip',
                // Пара «IP + порт» — уникальный адрес принтера в сети.
                // whereNull('deleted_at') обязателен: правило `unique` не знает
                // про softDeletes и иначе считает удалённые записи занятыми.
                Rule::unique('printers', 'ip_address')
                    ->where(fn ($query) => $query
                        ->where('port', $this->arg('port'))
                        ->whereNull('deleted_at')),
            ],
            'port' => ['required', 'integer', 'between:1,65535'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ip_address.unique' => 'Принтер с таким IP-адресом и портом уже существует.',
        ];
    }
}
