<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\AdminSetting;
use Throwable;

/**
 * Формирует тексты SMS с обязательным упоминанием названия сервиса.
 *
 * Операторы связи блокируют SMS с кодом или паролем, если в тексте нет
 * названия сервиса, от которого пришло сообщение (требование при работе
 * с бесплатным именем отправителя). Название берётся из настройки
 * `sms_service_name`, чтобы на каждом проекте подставлялся свой бренд.
 */
class SmsTextBuilder
{
    /** Ключ настройки с названием сервиса (admin_settings). */
    public const SERVICE_NAME_KEY = 'sms_service_name';

    /** Ключ-fallback: название проекта в админке. */
    public const FALLBACK_KEY = 'admin_title';

    /**
     * Название сервиса для подстановки в текст SMS.
     *
     * Цепочка: настройка `sms_service_name` → `admin_title` → env
     * `SMS_SERVICE_NAME` → `APP_NAME`. Fail-safe: ошибка чтения БД
     * не должна ронять отправку — вернём значение из конфига.
     */
    public static function serviceName(): string
    {
        $name = null;

        try {
            $name = AdminSetting::getValue(self::SERVICE_NAME_KEY);

            if (blank($name)) {
                $name = AdminSetting::getValue(self::FALLBACK_KEY);
            }
        } catch (Throwable $e) {
            $name = null;
        }

        if (blank($name)) {
            $name = config('services.sms.service_name') ?: config('app.name');
        }

        return trim((string) $name);
    }

    /**
     * Текст SMS с кодом подтверждения.
     *
     * «Код 82342 для входа в Портал Турбо. Никому не сообщайте.»
     */
    public static function confirmationCode(string $code): string
    {
        $service = self::serviceName();

        if ($service === '') {
            return "Код {$code}. Никому не сообщайте.";
        }

        return "Код {$code} для входа в {$service}. Никому не сообщайте.";
    }

    /**
     * Текст SMS с паролем от личного кабинета сотрудника.
     *
     * «Пароль 4Kd82a для входа в Портал Турбо. Никому не сообщайте.»
     */
    public static function password(string $password): string
    {
        $service = self::serviceName();

        if ($service === '') {
            return "Пароль {$password}. Никому не сообщайте.";
        }

        return "Пароль {$password} для входа в {$service}. Никому не сообщайте.";
    }
}
