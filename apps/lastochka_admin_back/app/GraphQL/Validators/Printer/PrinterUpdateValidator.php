<?php

namespace App\GraphQL\Validators\Printer;

use App\Models\Printer;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class PrinterUpdateValidator extends Validator
{
    public function rules(): array
    {
        $id = $this->arg('id');
        $printer = Printer::find($id);

        // Проверять уникальность пары нужно по итоговым значениям: обновление
        // партиальное, неизменённое поле в аргументы не приходит.
        $ipArg = $this->arg('ip_address');
        $ip = $ipArg ?? $printer?->ip_address;
        $port = $this->arg('port') ?? $printer?->port;

        return [
            'id' => ['required', 'integer', Rule::exists('printers', 'id')->whereNull('deleted_at')],
            'title' => ['nullable', 'string', 'max:255'],
            'ip_address' => [
                'nullable',
                'string',
                'ip',
                Rule::unique('printers', 'ip_address')
                    ->ignore($id)
                    ->where(fn ($query) => $query
                        ->where('port', $port)
                        ->whereNull('deleted_at')),
            ],
            'port' => array_merge(
                ['nullable', 'integer', 'between:1,65535'],
                // Если меняют только порт, правило на ip_address не выполнится
                // (Laravel пропускает правила отсутствующих полей) — вешаем
                // зеркальную проверку на порт.
                $ipArg === null
                    ? [
                        Rule::unique('printers', 'port')
                            ->ignore($id)
                            ->where(fn ($query) => $query
                                ->where('ip_address', $ip)
                                ->whereNull('deleted_at')),
                    ]
                    : []
            ),
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ip_address.unique' => 'Принтер с таким IP-адресом и портом уже существует.',
            'port.unique' => 'Принтер с таким IP-адресом и портом уже существует.',
        ];
    }
}
