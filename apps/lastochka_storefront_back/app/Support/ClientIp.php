<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Внешний IP клиента за upstream-проксей.
 *
 * Приложение стоит за проксей заказчика, поэтому реальный адрес клиента —
 * первый элемент X-Forwarded-For; без заголовка (локальное окружение,
 * прямой доступ) — адрес соединения. Trusted proxies глобально не
 * настраиваются намеренно: изменение request()->ip() задело бы IP-проверку
 * вебхука YooKassa.
 */
final class ClientIp
{
    public static function fromRequest(Request $request): string
    {
        $forwardedFor = $request->header('X-Forwarded-For');

        if ($forwardedFor !== null && trim($forwardedFor) !== '') {
            return trim(explode(',', $forwardedFor)[0]);
        }

        return (string) $request->ip();
    }
}
