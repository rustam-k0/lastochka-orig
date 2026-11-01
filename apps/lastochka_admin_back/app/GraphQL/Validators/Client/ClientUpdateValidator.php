<?php

namespace App\GraphQL\Validators\Client;

use App\Models\Client;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class ClientUpdateValidator extends Validator
{
    public function rules(): array
    {
        $clientId = $this->arg('id');

        $emailRules = ["nullable", "email", "max:255"];

        $client = Client::withoutGlobalScopes()->find($clientId);
        if ($client && $this->arg('email') !== $client->email) {
            // Уникальность email только среди активных авторизованных клиентов:
            // исторические soft-deleted и is_authorized=0 записи (в т.ч. зомби-дубли,
            // оставшиеся до дедупа) не должны блокировать редактирование живого клиента.
            $emailRules[] = Rule::unique((new Client())->getTable())
                ->whereNull("deleted_at")
                ->where("is_authorized", 1)
                ->ignore($clientId);
        }

        return [
            "name" => ["nullable", "max:255"],
            "surname" => ["nullable", "max:255"],
            "patronymic" => ["nullable", "max:255"],
            "email" => $emailRules,
            "birthday" => ["nullable", "date"],
            "gender_type_id" => ["nullable", "integer", "in:1,2"],
            "admin_note" => ["nullable", "string", "max:2000"],
        ];
    }
}
