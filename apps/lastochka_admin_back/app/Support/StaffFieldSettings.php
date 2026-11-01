<?php

namespace App\Support;

use App\Models\AdminSetting;

/**
 * Настройки полей карточки сотрудника (Настройки → Общие → Сотрудники).
 *
 * Обязательность почты — форк-зависимая: у части проектов сотрудники заводятся
 * без email, поэтому флаг по умолчанию выключен и включается на стенде, где
 * почта нужна (MID-293). Один источник истины для валидаторов UserCreate /
 * UserUpdate и для формы админки (запрос adminSetting).
 */
class StaffFieldSettings
{
    public const EMAIL_REQUIRED_KEY = 'staff_email_required';

    /**
     * Почта сотрудника обязательна. Fail-safe: любое значение, кроме '1'
     * (в том числе отсутствие записи в admin_settings), означает «не обязательна» —
     * форк, где миграция ещё не накатилась, сохраняет прежнее поведение.
     */
    public static function emailRequired(): bool
    {
        return AdminSetting::getValue(self::EMAIL_REQUIRED_KEY) === '1';
    }
}
