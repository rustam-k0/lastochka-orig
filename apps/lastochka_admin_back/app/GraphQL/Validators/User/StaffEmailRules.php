<?php

namespace App\GraphQL\Validators\User;

use App\Models\User;
use App\Support\StaffFieldSettings;
use Illuminate\Validation\Rule;

/**
 * Правила поля email сотрудника: обязательность зависит от настройки
 * staff_email_required (MID-293), уникальность действует всегда — дубль почты
 * ломает вход в админку независимо от того, обязательно поле или нет.
 */
trait StaffEmailRules
{
    /**
     * @param int|string|null $ignoreUserId ID редактируемого пользователя (update),
     *                                      его собственная почта не считается дублем.
     */
    protected function staffEmailRules($ignoreUserId = null): array
    {
        $unique = Rule::unique(User::table)->whereNull('deleted_at');
        if ($ignoreUserId !== null) {
            $unique = $unique->ignore($ignoreUserId);
        }

        if (!StaffFieldSettings::emailRequired()) {
            return ['nullable', 'email', $unique];
        }

        // sometimes нужен только для update: частичный UserUpdate без ключа email
        // почту не трогает, а переданный ключ обязан быть непустым.
        $required = $ignoreUserId !== null
            ? ['sometimes', 'required']
            : ['required'];

        return array_merge($required, ['email', $unique]);
    }
}
